# MAX client history — review draft

No commit, push, production deployment or production SQL has been performed for this change.
Android and add_max_messages.php are unchanged; there is no schema migration.

## Data flow and access

Existing call/email client groups remain the only entry points. No MAX-only clients are created.
The new POST client_max_history.php uses requestJson's same-origin session and CSRF and the
existing requireWebUser/webManagerScope checks and persisted call/email access rules. Input is an existing
call_id or email_id plus optional inclusive YYYY-MM-DD from/to (default 2000-01-01 through
business today). Browser-supplied owner, manager, name, phone or timezone do not authorize data.

The persisted call's phone/email directory lookup must yield one stable client ID. Email
contexts retain different client IDs even if display names match; the legacy detail helper
collapses names and is therefore not used for this MAX-specific email lookup. No MAX
contact_name/user ID/shortcut is used. Because directory detail responses contain only the matched
phone, the helper rehydrates the full raw client record through the existing indexed phone cache.
Every candidate phone is independently checked against the cache: unknown/blank/shared numbers
are excluded. Unavailable cache produces a graceful 503 instead of fabricated zero counts.
No phone-to-client fallback by name is allowed. Clients without a stable directory ID are excluded.
One client may supply multiple confirmed phones; IN membership does not duplicate source rows.

A manager is always restricted to web-user-{authenticated id}. An admin/supervisor can use an
accessible persisted interaction, but its owner's user_phone scopes MAX data. For email-only
admin contexts, an exact, unique web_users.display_name resolves that owner; ambiguous names
fail closed. Existing permissions are reused, not replaced. The main UI also skips mixed-owner
or ambiguous-client groups rather than choosing a random context.

## Aggregation and time

There is no shared server business-timezone setting; the Clients refresh-job timezone is not a
history setting. MAX_HISTORY_TIMEZONE is explicitly Europe/Volgograd. No browser timezone is sent.

PHP computes UTC ranges and constant-offset intervals using IANA rules. SQL applies owner, phone
and raw UTC time predicates, then groups by DATE(TIMESTAMPADD(SECOND, business_offset, occurred_at)).
This works without MariaDB timezone-table installation. Backend merges interval results by local
date, summing incoming/outgoing and taking the latest UTC timestamp. This also handles a business
offset change splitting one local day. Raw rows never reach the frontend.

Response shape:

```json
{"status":"success","data":[{"date":"2026-10-19","incoming_count":6,"outgoing_count":4,"last_event_at":"2026-10-18T21:40:00Z"}]}
```

Only these four aggregate fields are returned. A message body/preview and MAX technical IDs are
never selected or sent. Existing UNIQUE(source_event_id) prevents redelivery increasing counts.
Query phones are bounded to 100 and dates to 2000–2100; no call/ingestion endpoint is modified.

Both the existing horizontal timeline and client-history modal render compact cards with the
exact title «Переписка MAX» and both direction counts including zero. Date/time display is fixed
to the business zone. MAX uses its latest event's business wall-clock time for comparison with
existing call/email wall-clock values. Existing ordering, including sales-last within a day,
is retained. Existing grouping, call counts, durations and purchase totals are not changed.

At most four MAX requests run concurrently. Results (including empty arrays and errors) and pending promises are cached per authenticated
user and unambiguous directory client ID. Interaction IDs only supply the first authorized
backend context; they are never cache keys. Timeline and modal share full-history results,
with timeline date filtering done locally. Normal call-data refresh invalidates the cache.
Errors do not cause retries through other references in the same load cycle. Stale modal responses
are ignored after closing/reopening or changing user. API errors leave existing events visible.
Different existing rows for phones of the same stable client receive one main-timeline MAX block,
without rebuilding those client groups; opening either interaction can show the full daily history.

## EXPLAIN and indexes

Real prepared query on isolated MariaDB with 5,000 unrelated fixture rows, two client phones,
owner filter and a three-day range:

```
table: max_messages
type: range
possible_keys: idx_max_messages_user_time,idx_max_messages_phone_time,idx_max_messages_time
key: idx_max_messages_phone_time
rows: 19
Extra: Using index condition; Using where; Using temporary; Using filesort
```

The existing phone/time index is used. Temporary grouping/filesort is expected for a computed
local day. No new index is justified by this plan, and none is added. Production plan was not read.

## Validation

- max_message_history_test.php: identity, full-record phones, ambiguous/unknown names/phones,
  cache failure, date validation and UTC business-midnight boundaries — passed.
- max_message_history_db_test.php: real MariaDB 6/4, N/0, 0/N, multiple phones/days/clients,
  NULL/blank phone, owner isolation, unique-event dedup, response allowlist, offset transition,
  and EXPLAIN — passed in calltrack_max_sync_test. Test data is removed afterwards.
- max_message_history_http_test.py: 25 real HTTP checks for session/CSRF, manager A/B isolation,
  admin/supervisor, call and email contexts, forged owner/timezone ignored, dates, response keys,
  and existing calls/purchase context endpoints — passed.
- max_message_history_ui_test.js: actual renderers, counters/zero counts, one card, call/email/
  sale ordering, multiple phone identity and no technical fields; passed under UTC,
  Europe/Volgograd and America/Los_Angeles. All six repository Node test scripts passed.
- max_message_history_browser_test.py: actual Chromium timeline and modal, counts and business
  time in two browser zones, existing calls/email/sales/purchase summary, graceful API failure — passed.
- Existing shared browser regression (opened name, tags, filters and stale response) — passed.
- Existing PHP identity/tags/sales/purchase metrics/analytics/web auth/shared Android auth tests — passed.
- Existing client_workspace_browser_test.py stops at its old expected «41 ₽» while the current
  formatter renders «41,00 ₽». The same failure was reproduced serving unmodified HEAD HTML/api.js
  against the same isolated fixtures. That test and purchase formatting were not changed.
- PHP lint and git diff --check passed. Android build was not run: Android is unchanged.

## Reproducing integration tests locally

Use only a disposable database named calltrack_max_history_test with CALLTRACK_TEST_FIXTURES=1,
existing developer PHP/MariaDB containers, CALLTRACK_STORAGE_DIR pointing to disposable storage,
and SALES_JOURNAL_BASE_URL pointing to the local fixture server. The opt-in
fixtures/max_history_seed.php refuses another database; it installs existing schemas, synthetic
users/calls/email/client cache and MAX rows. max_history_sales_router.php is a local upstream stub
supporting both existing main-timeline phone queries and purchase-summary name queries.
No real user credentials or data are used.

Run the HTTP/browser scripts with MAX_HISTORY_TEST_URL / MAX_HISTORY_TEST_PAGE pointing to the
local server, and CALLTRACK_TEST_CHART pointing to a local Chart.js copy. Never use these fixtures
or the root/empty-password DB test against production. There is no deployment performed or
required migration to execute as part of review.

## Final integration smoke test / N+1 regression

`max_message_history_requests_test.js` exercises the actual frontend request/render functions:
three calls with data or empty results each make one request; mixed call/email references
also make one; two clients make two; simultaneous accesses share one pending promise.
401/403/500/network/timeout errors preserve existing rendering and do not cascade.
Explicit cache invalidation permits a retry in the next load cycle.

`fixtures/max_history_real_phone.php` adds only synthetic rows in the explicitly guarded
calltrack_max_history_test database. Run it after the base fixture tests.
`max_message_history_real_phone_test.py` verifies the provided real-format example through
authenticated HTTP and Chromium timeline/modal: +7 (989) 282-98-42 matches 9892829842,
0 incoming / 1 outgoing, UTC 2026-10-18T21:30:00Z becomes business date 2026-10-19.
The same phone under another owner is excluded. Manager/admin/supervisor contexts are checked.
Chromium checks all error modes, empty results, preserved calls/email/sales, and request counts
in UTC and America/Los_Angeles. This is a synthetic reproduction, not production DB access.
SQL is unchanged by the N+1 fix; the isolated EXPLAIN still uses the phone/time range index.
