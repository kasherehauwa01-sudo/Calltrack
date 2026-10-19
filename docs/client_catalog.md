# Clients tab

The tab reuses the existing Clients cache, IDs and history workspace. No second directory,
new database table, index or migration is introduced. Production is not accessed or deployed.

## Assignment and list

`fields.Менеджер` is the source of assignment, supplied by the existing Clients import.
It is a full-name string. Match it against active manager accounts using the existing
`managerNameKey()` convention (case, spacing, name order). Require one matching account;
missing, structured or ambiguous values are not assigned to a manager automatically.
The source client `id` is used throughout. Never use a display name, row index or phone as ID.
Duplicate IDs are excluded. Admin/supervisor can list unassigned clients too.

The new endpoints reuse `requireWebUser()` and `webManagerScope()`. A manager sees only
records assigned to their authenticated account. Manager/user_phone supplied by the browser
cannot expand the result. Admin/supervisor see the directory and can filter by a manager ID.
The legacy public Clients/Android endpoints are deliberately unchanged, per the clarified scope;
this feature does not claim to remediate their previously documented access issues.

`GET clients_list.php`: search, manager (account ID), activity, page, page_size.
The response contains items, total, page, page_size and manager filter options.
Default page size is 50, maximum 100. Filters/fixed purchase sorting/pagination run on the server.
Public items contain only id, name, phones, manager, last_activity_at, last_purchase_at.
Search is case-insensitive by name and ignores phone punctuation/country prefix; partial phone
search is also supported. Empty results, loading, retry, stale-request guards and a 300ms
search debounce are implemented. The manager selector is hidden for manager-role users.

Activity follows the existing horizontal history's communication sources: working incoming/
outgoing calls of at least 10 seconds, excluding personal calls, and outgoing Email.
Use the latest persisted timestamp across all history, independent of the currently displayed
period. Sales recency and MAX-only messages are not redefined as directory activity.
Last activity is displayed in the existing communication wall-clock convention.
Activity filters: all, yesterday, last 7 calendar days, last 30 calendar days, no activity for
30 days (including clients with no activity). Date boundaries use Europe/Volgograd.
Fixed order: last purchase descending (no purchases last), then name and stable ID.
The sort selector is removed.

The normalized directory is cached for 120 seconds, with source-file revision invalidation.
Manager names are indexed once rather than normalized for every client/account pair.
Bulk communication aggregates (two queries) are cached for 60 seconds per authenticated scope
and directory/account revision. Empty results are cached too. Searches reuse these projections,
abort superseded browser requests and release the PHP session lock before read work.
Filtering and sorting still scan the derived directory; no production performance measurement
is claimed. No schema/index changes are made.

## Last purchase dates

Dates come from the existing **all-time name-based Sales Journal history**, not communication
activity or a new Clients property. A CLI background worker requests batches of at most 100
unique names using the existing client-sales contract. The maximum sale_date is indexed by
stable directory ID. No per-row history requests are made from the browser or list endpoint.
Only a complete successful calculation is atomically published. A source revision change
invalidates the index; failed refreshes never publish a partial result. A same-revision complete
index can be served while refreshing. The index refresh interval is 10 minutes (the existing
Sales Journal cache can serve stale data during an upstream outage).

On first load, HTTP 202 reports preparation progress; the browser polls every two seconds.
Dates are not fabricated and the initial list waits for a complete index, so global sorting
is correct before pagination. Later refreshes retain the list and poll every three seconds.
Leaving the tab cancels polling. Failures show retry; rapid retries have a cooldown.
The server needs writable configured storage, PHP CLI and exec/nohup. Optionally set
CALLTRACK_PHP_CLI to the CLI binary. The worker can also be invoked explicitly:
`php api/refresh_client_purchase_dates.php`. No production configuration is changed here.

## One existing history component

Clicking a list row POSTs only client_id to `client_events.php`. The server re-resolves the
record and assignment; it does not use a browser owner/name/phone. Calls are limited by the
resolved owner's user_phone, Email by the canonical owner name. Shared phones/addresses are
excluded rather than assigned to an arbitrary client. No message bodies are selected.
Then the existing `openClientHistoryModal()` receives the loaded events, and the same
`clientWorkspace.open()` renders the existing purchase summary/analytics/tree.
The Email card renderer is shared by horizontal history and the modal.
The list itself never loads a history per row. Opening the Clients tab does not initiate
MAX requests for the hidden horizontal history.

`clientHistoryContext()` accepts an additional client_id route. It reuses directory assignment
instead of requiring a synthetic call_id/email_id. This works for clients with no interactions.
Existing interaction-based requests keep their previous behavior. Candidate/tag selection for
direct history is restricted to the resolved record, even if a phone is shared.
The purchase upstream is still name-based: where different stable clients share a name,
direct purchase loading fails closed rather than merging their sales. Calls/Email/MAX still
render. An unresolvable manager account has no guessed communication owner.

MAX uses the existing max_messages query/aggregation and Europe/Volgograd display rules;
only context resolution and the frontend request gain client_id support. The direct-directory
MAX cache uses stable client ID plus the server-resolved manager ID, separate from old
interaction contexts, so a different interaction owner cannot supply a cached result.
Incoming/outgoing Android capture, ingestion and sync are unchanged.

## Validation (local disposable fixtures only)

- `client_catalog_test.php`: role filtering, exact manager-name mapping/ambiguity, name and
  formatted phone search, manager filters/forgery, all activity filters, purchase ordering, pagination,
  no-activity client, shared addresses, duplicate IDs and response allowlist.
- `client_catalog_db_test.php`: bulk activity uses exactly two queries for both 1 and 111
  clients; current owner activity is isolated. Isolated EXPLAIN uses idx_user_phone (ref).
  No production EXPLAIN and no schema/index changes.
- `client_catalog_http_test.py`: actual authenticated endpoints, three roles, foreign client
  ID rejection in events/purchases/MAX, owner injection, paging/search, no-call context and MAX.
- `client_catalog_browser_test.py`: actual page/API in Chromium, all roles, no eager history,
  one list request, pagination/search/manager filters, no-call client, shared modal including
  calls/Email/MAX and purchase analytics, old entry point, mobile and retry/empty state.

Run the new fixture `tests/fixtures/client_catalog_seed.php` only after the existing local
MAX-history fixtures, with CALLTRACK_TEST_FIXTURES=1 and DB calltrack_max_history_test.
It refuses other databases. Browser scripts target only 127.0.0.1:8090 and use local Chart.js.

`client_catalog_purchases_test.php` checks batched all-time histories, latest dates, no purchases,
upstream failures and caching of empty successful results with key invalidation.
