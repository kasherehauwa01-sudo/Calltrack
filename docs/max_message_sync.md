# Personal MAX message metadata sync

The Android detectors and MAX calls are unchanged. No client matching by name is performed.
Room remains version 11; the new DAO adds queries, not a table or migration.

## API contract

`POST https://<existing SQL_API_BASE_URL>/add_max_messages.php`, Android Bearer authorization.
The endpoint uses the same HTTPS/reverse-proxy convention as `android_auth_api.php`.
The production reverse proxy must overwrite X-Forwarded-Proto and forward Authorization.
Do not enable request-body or Authorization logging for this endpoint.

```json
{"messages":[{"source_event_id":"max-message-out-1792157746247-example","direction":"outgoing","contact_name":"Contact","phone":"","max_user_id":"","shortcut_id":"","occurred_at":1792157746247}]}
```

Each batch contains 1–100 rows, body at most 256 KiB. Android sends at most 50.
Timestamp is integer Unix milliseconds (2000–2100, at most 24 hours in the future).
Server stores `occurred_at` in UTC DATETIME, with millisecond precision truncated to seconds.
Limits in Unicode characters: event ID 190 (ASCII technical ID), name 255 (required),
phone 30, MAX ID/shortcut 190. Direction is incoming/outgoing only.
No text, sender or conversation fields are accepted. Unknown row fields are rejected,
except `manager/user_phone`, which are ignored and replaced by server auth identity.
An optional `is_group_conversation` must be false/0; true/unknown values are rejected.
A server cannot discover an unmarked group from a name: Android's verified detector,
persistent cutoff and local group flag are the boundary for that case.

Successful response, including already-stored IDs owned by the same account:

```json
{"status":"success","accepted":["max-message-out-1792157746247-example"]}
```

401 = missing/invalid Android auth; 405 = wrong method; 400 = invalid payload/HTTPS;
413 = body too large; 500 = unavailable persistence/account or identity collision.
No SQL error or payload is exposed. Whole batch validation precedes a transaction.
Unique source_event_id prevents duplicate inserts and duplicate retries do not overwrite
identity or event data. A collision owned by another authenticated account is not acknowledged.
There is no calls-table write and no automatic client matching.

## Android persistence and scheduling

On first launch with sync, an IO coroutine establishes `max_message_sync_v1` preferences.
It commits `enabled_at` plus the maximum existing local row ID atomically. Every production
store insert also passes through the same initialization mutex before insertion, even offline.
If initialization cannot persist, events are still saved locally; a later successful cutoff
excludes all such pre-initialization rows. Preferences are never reset on logout/retry/update.

Eligibility requires all of:

- uploaded=false, isGroupConversation=false, valid direction/nonblank contact name;
- id strictly above the initial high-water mark (old incorrectly marked groups excluded);
- both createdAt and occurredAt at/after enabled_at (old notification history excluded).

Historical local rows are retained unchanged and never marked uploaded just to hide them.
Clock rollback before enabled_at conservatively leaves events local-only.
No row is promoted by matching a contact name or by generating a MAX ID.

WorkManager registers a connected-network periodic job (15 min) and a unique immediate job.
App startup and successful store inserts enqueue work; KEEP coalesces concurrent requests.
The periodic job covers process death and an insert racing with an exiting immediate worker.
No HTTP is performed in notification/accessibility callbacks. Each worker sends at most three
50-row batches (150 events). Backlog/partial ack/HTTP/auth/IO errors return retry with exponential
backoff starting at 30 sec (WorkManager caps it). Reopening the app and periodic work also recover
failed/cancelled work. No saved auth => no payload request. Only acknowledged IDs from the sent
batch are marked uploaded. An interrupted update is safe: the next retry is server-idempotent.
The client uses existing AuthStore/AuthInterceptor, SQL_API_BASE_URL and OkHttp conventions;
BODY logging used by the unrelated Google webhook client is deliberately not reused.

The queue currently follows the installation's active Android login, like the existing local
call queue; it has no per-row account ownership. Do not switch users while an unsynced queue
exists. A separate ownership migration would be required to retain different users' backlogs.
Permanent invalid rows remain local and require investigation rather than being marked uploaded.

## Validation

`tests/max_messages_sync_test.php` covers validation/auth contract without DB.
`tests/max_messages_db_test.php` requires explicit isolated development DSN and exercises real
Android token lookup and MariaDB transactions, duplicate/ownership checks and literal SQL input:

```sh
MAX_SYNC_TEST_DSN='mysql:host=127.0.0.1;dbname=calltrack_max_sync_test' php tests/max_messages_db_test.php
```

This test uses root with an empty password and is exclusively for the isolated development DB.
Never point it at production. Standard Android checks: testDebugUnitTest, assembleDebug.
New JVM tests cover eligibility/cutoff, explicit DTO allowlist, acknowledgements, retries and limits.

## Manual production deployment (not executed by Codex)

Deploy PHP/SQL before enabling this Android version. Existing auth/config stay in place.
The examples assume a reviewed checkout containing the new commit, database `calltrack`,
and an existing mode-0600 MariaDB client option file with deployment credentials.
Substitute the real secure option-file path; never put a password on the command line.

```sh
cd /path/to/reviewed/Calltrack
SHA=$(git rev-parse HEAD)
release_dir="/tmp/calltrack-max-sync-$SHA"
backup_dir="/var/backups/calltrack-max-sync-$SHA"
mkdir -p "$release_dir" "$backup_dir"
git archive "$SHA" api/add_max_messages.php api/max_messages_service.php database/20261016_create_max_messages.sql | tar -x -C "$release_dir"
php -l "$release_dir/api/add_max_messages.php"
php -l "$release_dir/api/max_messages_service.php"
mariadb-dump --defaults-extra-file=/etc/calltrack/mysql-client.cnf --single-transaction calltrack > "$backup_dir/before.sql"
mariadb --defaults-extra-file=/etc/calltrack/mysql-client.cnf calltrack < "$release_dir/database/20261016_create_max_messages.sql"
install -m 0644 "$release_dir/api/max_messages_service.php" /var/www/html/vr/calltrack/api/max_messages_service.php
install -m 0644 "$release_dir/api/add_max_messages.php" /var/www/html/vr/calltrack/api/add_max_messages.php
curl --fail-with-body -X POST -H 'Content-Type: application/json' --data '{"messages":[]}' https://kvasmix.ru/vr/calltrack/api/add_max_messages.php
# Expected 401 without auth; curl's nonzero exit is expected for this probe.
```

Verify the SQL schema and a controlled authenticated request before distributing Android.
An actual Android APK is still blocked by the repository's existing duplicate sqlDateTime(Long)
in CallRepository.kt; resolve that independently in the user's working copy, never in this task.
The migration is additive; re-running CREATE IF NOT EXISTS is safe, but does not repair a
preexisting incompatible max_messages schema. Inspect such a table before deployment.

## Rollback (manual)

Stop/discontinue the sync-enabled Android build first. Disable only the new endpoint:

```sh
mv /var/www/html/vr/calltrack/api/add_max_messages.php "$backup_dir/add_max_messages.php.disabled"
# Keep max_messages_service.php and the max_messages table/data intact.
# Existing calls/auth endpoints and schema are unaffected.
```

Android leaves unacknowledged rows uploaded=false and retries; restoring the endpoint resumes
idempotently. Do not drop the table, clear cutoff preferences, reset uploaded flags or restore
a full DB backup over newer production calls. Retain the backup for deliberate recovery only.

## Results for this change

- 46 selected JVM tests passed (6 sync-core tests plus incoming, outgoing header, storage,
  migration and contact-resolution regression tests); 8 existing AuthStore recovery tests passed.
- The six existing MAX call state-machine tests ran: four passed, two fail on preexisting
  Integer-versus-Long assertions at MaxCallStateMachineTest.kt:14 and :33. Neither file changed.
- PHP validation/contract tests and real MariaDB plus HTTP endpoint tests passed on the isolated
  calltrack_max_sync_test database, including valid/invalid token, duplicate, ownership and SQL input.
- General PHP sweep: 38 passed, one isolated-DB test skipped without its opt-in DSN, ten failed.
  All ten also fail on the unchanged starting commit cb82e83:
  admin_updates_test.php, android_dial_layout_test.php, android_kotlin_encoding_test.php,
  client_calls_names_test.php, client_directory_test.php, help_import_invoice_test.php,
  install_update_for_all_test.php, max_call_integration_test.php, pwa_barcode_scanner_test.php,
  web_pwa_removal_test.php. These existing tests were not edited.
- testDebugUnitTest and assembleDebug were attempted together with --continue. Both are blocked
  at compileDebugKotlin solely by the existing duplicate sqlDateTime(Long) at CallRepository.kt
  lines 239 and 1186 and its ambiguous call sites. No debug APK was produced.
- No physical-device WorkManager/background delivery test or production deployment was performed.
