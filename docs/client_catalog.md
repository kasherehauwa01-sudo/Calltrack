# Clients tab: buyers from Sales Journal

The list source is real purchases in Sales Journal, not the entire Clients directory.
Calltrack GET clients_list.php authenticates the current web user, validates paging,
search/activity/manager filters and calls protected Sales Journal POST
/api/integrations/calltrack/buyers. Default page50, maximum100. Journal groups sales
by its existing lower(trim(client)) identity, computes all-history MAX(sale_date),
counts groups and applies ORDER BY last_purchase_at DESC/client_key + LIMIT/OFFSET.
Name/normalized-phone search selects buyers without narrowing the sales used for the
last-purchase calculation. Purchase activity supports yesterday,7,30,inactive; API also
retains today. The UI labels the filter «Активность покупок». No sort selector.

Manager assignment comes from the existing lightweight Clients name->manager integration,
never Sale.author. Exact case/spacing/name-order manager matching, no fuzzy selection.
Manager scope is generated from the authenticated Calltrack account, not frontend
user_phone/owner. Admin/supervisor may select a validated active manager ID. Calltrack
rechecks returned owners; upstream assignment errors never become an unscoped success.
Sales Journal's integration Bearer token stays backend-only. Existing call/email/MAX
history checks and CSRF remain. No production changes or new persistent tables.

The journal has no external Clients client_id. `sales_client_key` is a distinct reference,
not a fabricated external ID. Existing client_id and interaction entry points retain their
meaning. A selected buyer key is re-authorized upstream before any history access.
Only after click does Calltrack resolve its exact directory contact in bounded memory:
stream records/*.json when ready (delta source), otherwise initial clients.json; reuse
SQLite if already present. Never decode the huge directory projection. Cache a small
single-buyer contact context for60 seconds, keyed by source/buyer/owner revision.
Require a unique same-name, same-owner contact and globally unambiguous phones/email
before assigning communications; ambiguous/missing contacts still have purchase history.
Original stable Clients ID is retained when uniquely resolved, including existing MAX
history's further independent phone/owner checks. No guessing of a contact from a name.

Clients without purchases are excluded. Buyers without calls are included. On selection
use the existing shared client history/workspace (calls/email/sales/MAX). No per-row
history request. The list no longer invokes the old full-directory JSON projection,
activity aggregation, purchases-index builder or polling/warming protocol. Those legacy
helpers are not a fallback for this route. A missing /buyers upstream shows an explicit
upgrade error; it does not fall back to the huge JSON cache.

Sales Journal executes at most3 set queries for a populated page (count/groups/phones),
plus its cached lightweight manager map; no sale ORM/items hydration or per-buyer query.
Phones shared by multiple journal buyer identities are marked separately; Calltrack also
checks directory ambiguity after click. Textual partial searches can scan SQL candidates;
no claim that normalized expressions use the old raw-column index. No index/migration
was deployed automatically.

Requires coordinated deployment of the new Sales Journal endpoint and Calltrack.
Production has not been updated by Codex. Existing JSON/shards remain for other consumers.

Local validation: client_catalog_http_test.py and client_catalog_browser_test.py cover
three roles, search/page boundaries, no-call buyer, shared history, no eager history,
errors and existing entry points; appended HTTP cases reject forged buyer scope and
exclude a directory client without sales. sales_buyer_catalog_test.php validates bounded
requests and streams a45,353,781-byte/64,000-row source with2MiB PHP peak under64M cap.
Sales Journal test_calltrack_buyers.py runs an opt-in disposable PostgreSQL fixture with
100,004 buyers, page_size50,3 SQL queries, search, owner scope, shared-phone handling and
purchase recency filters. No production dataset was used in these checks.
