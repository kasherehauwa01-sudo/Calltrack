# Analytics Signals and manager tasks (disabled by default)

## Ownership and safety

ClientsVR ID is the canonical task target, `web_users.id` is the assignee. The sales engine is unchanged: `PURCHASE_OVERDUE` and `CHURN_RISK` remain stages of INACTIVITY. Do not infer IDs from phone/name equality. Existing Sales Journal SHADOW events always have `automation_allowed=false`, and cannot create tasks even if the consumer mode is `enabled`.

The production server has NOT been inspected. Before any rollout compare `/var/www/html/vr/calltrack` with this branch, including untracked files, schema, PHP version/extensions, runtime source URLs, cache backend and any server-only tasks. If a task module exists there, stop and reconcile the architecture before merging. No deploy/production migration is authorized by this document.

## Schema (manual review/application required)

1. Existing `web_users`/session tables must already exist.
2. Review and explicitly apply `database/20261020_manager_bindings.sql`.
3. Review and explicitly apply `database/20261020_analytics_tasks.sql`.
4. Review and explicitly apply `database/20261020_analytics_task_policy.sql`.

No new tables are created by ordinary API requests. User deletion is RESTRICTed for assigned tasks/bindings: deactivate accounts instead of destroying their history. Actor IDs in history remain historical references. Back up first. SQLite tests translate dialect syntax; MariaDB tests execute these SQL files unchanged.

## Manager aliases

Settings/Users → edit existing user → “Сопоставление с ClientsVR”. One exact normalized source designation belongs to one stable user ID; several designations may belong to a user. Admin+CSRF required for add/delete/transfer. Conflicts/stale revisions return409. Inactive/non-manager targets are rejected. All changes are audited. `managerNameKey` word-order matches are suggestions only, never grants to create tasks. Historical labels may remain unassigned. Cache card counts are not production/upstream counts or freshness evidence.

Both `fields.manager` and localized `fields.Менеджер` are accepted. Conflicting nonempty values quarantine assignment; neither spelling overwrites the other silently. Existing general catalog owner matching remains compatible by default. After reviewing the access implications, `CALLTRACK_MANAGER_BINDINGS_ENABLED=1` additionally makes explicit aliases authoritative in existing catalog/history owner resolution; inactive explicit targets cannot fall through to a different display-name match. Integration tasks always use explicit aliases regardless of this legacy-compatibility flag. Legacy Sales Journal buyers filters remain their existing display-name/name-map contract; aliases do not silently rewrite those filters. Verify coordinated legacy listing behavior before enabling catalog aliases on production.

## Client identity

Admin diagnostic signals → identity review stores a versioned binding from producer subject kind/version/key **and complete filter scope** to an existing unique ClientsVR ID. REVIEW/AMBIGUOUS/NOT_FOUND never authorize assignment. VERIFIED requires evidence and explicit confirmation of the entire historical sales group, plus classification as nontechnical (or an explicit technical flag which blocks automation). A matching name/phone is not verification. Shared phones and renamed clients do not merge IDs. All edits are audited; changing a binding/manager does not silently move existing tasks. No old sales rows are rewritten.

The current sales engine still groups by versioned normalized names; no source-provided 1C client ID was demonstrated. This bridge is reviewed historical attribution, not a claim that the sale importer now has stable direct IDs. Multiple historical keys may target the same client, but purchase features are not silently aggregated across aliases. A shared client/family open-task slot prevents duplicate work. A future direct source-ID path requires an explicit namespace/import contract. A ClientsVR merge/split needs reviewed rebinding and task review, not fuzzy backfill.

Clients cache membership is not the entire upstream directory. Legacy normalization can omit cards without phone/name; an absent/duplicate ID is blocked. The new resolver itself never requires a phone. Safe bounded source-ID lookup for omitted cards remains a pre-pilot integration decision; no phone fallback is used.

## Tasks and authorization

Existing navigation → Tasks. Admin creates/assigns manual tasks to active managers and an existing ClientsVR ID. Managers read/update only their own tasks. Supervisors have read access and no new write permission. Admin can change assignee, priority, deadline and cancel; DONE/CANCELLED require a result/reason. Terminal tasks cannot reopen automatically. Optimistic versions reject stale edits. No role/account changes are made by this module.

States: OPEN, IN_PROGRESS, DONE, CANCELLED. Priorities: LOW, NORMAL, HIGH, URGENT. Deadlines stored UTC. Manual task creation is independent of automation policy. Category INACTIVITY reserves a client/family active slot; MANUAL allows unrelated work. Task card uses existing client history entry point, which independently rechecks current catalog access; aliases require the explicit compatibility flag above. It shows immutable source evidence and task history.

New purchases/resolution/suspension flag existing work for review; user tasks are not auto-closed. Assignee/binding changes flag review and do not transfer work. Same episode after completion never creates another task. A genuinely new episode may create a new task after the previous active slot is released. Existing work assigned to another manager requires review. Completed-task results remain user-owned.

## Transport and idempotency

Sales Journal protected GET API:
- `/api/integrations/calltrack/analytics/publications?after=0&page_size=50`
- `/api/integrations/calltrack/analytics/runs/{id}/changes?after=0&page_size=50`
- `/api/integrations/calltrack/analytics/latest`

Existing server-to-server Bearer token/transport is reused. No token enters browser code. Publication sequence is allocated under one locked singleton and committed with the published-run pointer. The original audit change ID is only a cursor inside a frozen completed run, never a global commit-order cursor. Worker must remain a single leader. Migration bootstraps already completed runs with worker stopped.

Consumer `api/analytics_sync.php` is CLI-only. `analytics_sync_state` freezes current publication/run/page. Each receipt and task link commit transactionally. Crash before page checkpoint causes safe replay. A connection-scoped MySQL lock serializes consumers; DB uniqueness protects against concurrent manual creators. At most5 pages (50 events/page) per invocation; timeouts/failure exit nonzero, retain checkpoint and retry next invocation. Invalid individual events/conflicting revisions enter `analytics_delivery_rejections`, visible to admins, instead of blocking every later event. Network/mixed-run/page contract errors retain checkpoint and raise an alarm. Do not skip/reset cursors to recover.

Receipt identity is source+signal_id and revision. Same revision/content is replay; old revisions ignored; same revision/different content or changing subject is rejected. Historical event payloads/processing decisions and task/user history remain separate. No unbounded call-sales endpoint or whole-directory JSON decoding is used by the new resolver; SQLite performs bounded ID lookup, otherwise an existing streaming reader is used once per page.

## Modes and business policy

`CALLTRACK_ANALYTICS_MODE` defaults to `disabled`:
- disabled: CLI performs no network requests or DB writes.
- dry_run: stores local diagnostics/checkpoint/receipts, never creates or changes tasks.
- enabled: still requires every gate; it is not approval by itself.

Tasks → Analytics signals → Policy configures minimum confidence, priority, calendar due days and enable flag independently for each signal type. Initial confidence/priority/days are NULL; type flags and approval are false. Saving a draft always revokes approval. Explicit separate business approval is required on the saved revision; no values were approved for production during development. Changes/approval are admin-only, CSRF-protected, versioned and audited. Approval is rechecked under the task transaction lock so revocation cannot be bypassed by an old CLI config. A file cannot override DB approval. UI per-signal dry-run refreshes all blockers without creating tasks.

## Source and scope contract (currently unresolved)

`CALLTRACK_ANALYTICS_POLICY_FILE` points to an operator-controlled file OUTSIDE web root/Git containing source facts, approved scope hashes and generator versions. This is explicit externally verified evidence, NOT an automatic freshness detector and NOT a claim that sync success proves upstream completeness. Missing/expired/unknown facts block automation.

Example structure (intentionally unusable placeholders; do not fill by guessing):

```json
{
  "approved_scope_policies": [],
  "approved_scope_hashes": [],
  "approved_generators": [],
  "max_signal_age_seconds": null,
  "sales": {
    "upstream_confirmed": false,
    "source_revision": null,
    "complete_through": null,
    "verified_at": null,
    "valid_until": null
  },
  "clientsvr": {
    "upstream_confirmed": false,
    "source_revision": null,
    "cache_revision": null,
    "complete_through": null,
    "verified_at": null,
    "valid_until": null
  }
}
```

Approval requires last closed business day in Europe/Moscow, confirmed accepted coverage, identical run/source revisions, nonexpired signal and source evidence, exact cache generation, complete exact scope hash, verified identity, nontechnical card, eligible active explicitly mapped manager, approved quality/priority/deadline and type enable. Refresh lock is shared with the existing cache writer while reading/resolving/creating tasks. Unknown status is fail-closed.

**Important limitation:** existing Sales Journal source resolver provides at most DECLARED and retains `mode=SHADOW`, `automation_allowed=false`. This implementation deliberately does not promote it via a config switch. Before genuine automatic production signals, a reviewed upstream completeness/release contract is required. Fully approved synthetic envelopes test the consumer's success path only; they are not producer permission or validation of real-customer assignments.

The user-requested Aviator prices Оптовые/Корпоративные are different from ClientsVR buyer types Оптовик/Корпоративный. No equivalence or scope change has been implemented. Current scope policy cannot be silently treated as the price-filter export.

## Monitoring, launch and rollback

Admin diagnostics: received/passed/review/blocked (review workload may overlap safety-blocked receipts), missing eligible manager, created/existing/completed tasks, per-signal blockers and rejected deliveries. Compare checkpoint progress, nonzero CLI exits, rejections, expired source attestations, unresolved identity/mapping and historical task outcomes. Logs report error classes/codes, never tokens or contact lists. Delivery rejections require investigation, not silent deletion. No retention job deletes unread publications/history.

Launch only after explicit deployment permission: compare server code/schema → backup → stop analytics worker/consumer → review/apply migrations → deploy disabled → verify disabled emits zero requests → approved isolated staging test → dry_run → manually review identities/aliases/source completeness/parameters → explicit business approval/pilot. Do not install cron or enable production in this development task. `scripts/sync_analytics_cron.sh` inherits mode default-disabled; it never installs cron.

Rollback: set mode disabled and stop scheduled consumer, preserve all inbox/checkpoint/tasks/history. Stop Sales worker before rolling back publication schema/code. Prefer code rollback with additive tables retained; destructive DROP requires backup, task/history export and separate permission. Do not close/delete user tasks during rollback. Reenable requires fresh evidence and reconciled checkpoint, not replay by deleting rows.

## Verification

- Python engine/freshness/transport tests use disposable PostgreSQL schemas only.
- PHP unit tests: manager_bindings_test.php, analytics_tasks_test.php, analytics_policy_test.php, analytics_sync_test.php.
- MariaDB runs apply checked-in migration SQL unchanged; use guarded `analytics-test-mariadb` host and disposable `analytics_*_test` databases.
- analytics_concurrency_test.php starts two task writers and verifies one open slot/task.
- analytics_http_browser_test.py uses only127.0.0.1 opt-in fixture; actual PHP session/CSRF/role APIs and Chromium. Fixture router refuses nonfixture DB names.
- Existing client_catalog_test.php and sales_buyer_catalog_test.php regression tests. Existing client_directory_test.php fails on the unmodified baseline too (old expected payload omits preserved fields); not rewritten to hide failure.

## Preflight and 399-candidate replay

See [read-only server comparison](analytics_server_readonly.md) and [isolated399 replay](aviator_399_dry_run.md). No production read/write or enabling permission is implied. The replay consumes a private checksum-pinned CSV into in-memory diagnostics with network disabled; it is not a publication or recalculation of the engine. Recommended0.65/NORMAL3/HIGH1 settings remain unapproved, unset in migrations and off by default.
