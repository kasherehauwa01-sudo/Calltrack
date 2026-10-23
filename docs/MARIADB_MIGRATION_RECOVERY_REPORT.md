# MARIADB_MIGRATION_RECOVERY_REPORT

PRODUCTION DEPLOYMENT = BLOCKED — NO VERIFIED RECOVERY.
This tooling does not authorize production writes, migration, deployment or automation.

Base: feat/analytics-manager-bindings8903765a74399493d3eb3830d15641c258a738ae (PR507).
Old PHP530adc1 worked on the upgraded schema in the prior isolated audit. That does
not mean an old enabled consumer enforces the new budgets: keep automation OFF.

## Cause and scope

MariaDB DDL implicitly commits; a multi-statement SQL file is not one reversible
transaction. Prior native reproduction created policy then failed on a later table;
ROLLBACK did not remove policy, and a blind retry failed Table already exists.
The original SQL files are unchanged. Instead of adding unchecked IF NOT EXISTS,
we add a standalone CLI-only verification/resume mechanism, never a web request hook.

Dependencies, in reviewed order:

1. Existing compatible web_users (InnoDB, unsigned BIGINT primary ID), separately
   managed auth/security history. This tool never recreates users or changes roles.
2. 20261020_manager_bindings.sql — bindings and history, FK web_users.
3. 20261020_analytics_tasks.sql — client bindings; manager_tasks/history/open slots;
   inbox/events; shadow cursor/rejections; task FK web_users, slot FK task.
4. 20261020_analytics_task_policy.sql — unapproved policy/types/audit.
5. 20261022_analytics_budget.sql — lifetime budget/audit and approved-feed cursor.
6. 20261023_analytics_daily_budget.sql — fixed pilot policy, date counters, unique
   reservations; bucket FK/RESTRICT and indexes/checks. Dependency on lifetime budget
   is an APPLICATION requirement; daily SQL alone cannot prove prerequisites applied.

## Mechanism

- tools/analytics_migrate.php: inspect by default; apply only with `--apply --confirm-maintenance`. Rejects ambiguous options, open caller transactions,
  disabled FK/CHECK enforcement and non-MariaDB connections.
- tools/analytics_migration_state.php: full known-object catalog comparison before
  any seed read or write, then policy/seed/counter invariants.
- tools/analytics_schema_contract.json: reviewed physical definitions captured from
  real MariaDB11.8.6 after running the actual five SQL files, with per-file SHA256.
  Contains schema metadata only, no client/user data or secrets. Contract version,
  whitelist and the complete CREATE/seed table inventory are validated before use. Future SQL changes
  without an explicit reviewed contract update are blocked by checksum.
- Columns/order/types/nullability/defaults/charset/collation/generated expressions,
  engine, PK/unique/other indexes, CHECK and FK columns/reference schema/actions are
  compared. AUTO_INCREMENT next values and index cardinalities are not reset/compared.
- Mutable, valid existing task policy/budget/cursor rows are preserved, not compared
  to their zero defaults. Immutable daily version/timezone/cap must match the contract.
  Counter/ledger mismatch is refused. An approved policy with missing business fields
  is not blessed merely because tables exist.
- Missing seed is initialized only in a recognized empty safe state. Existing automatic
  tasks, inbox/processing or quota evidence make zero-counter/cursor initialization
  unsafe; STOP for manual review. No inference or reset over operational history.
- GET_LOCK per database serializes cooperating runners, including inspect; contention
  returns MIGRATION_BUSY. A crashed connection releases the lock. Each completed DDL
  is already committed; next run re-inspects actual objects, never trusts a marker.
- Apply creates only wholly missing verified objects and inserts missing safe seed
  statements. It skips verified existing objects/rows. No DROP/ALTER/TRUNCATE/UPDATE,
  no disabling foreign_key_checks, no overwrite or reinitialization of used quotas.
- Logs contain state, migration/table/action and error class, not raw SQL error row
  values, passwords or PII. On failure inspect again; partial progress may be committed.
- Both PHP tooling entrypoints return HTTP404 under non-CLI SAPI, even with apply
  arguments. They cannot become an unauthenticated web migration endpoint.

| State | Meaning | Action |
|---|---|---|
| NOT_APPLIED | Known prerequisites, migration objects absent | Inspect/review; separate permission before apply |
| PARTIALLY_APPLIED | Only known valid prefix/missing objects or safe missing seeds | Explicit locked apply skips verified steps and continues |
| APPLIED_AND_VERIFIED | Full known definitions and seed/state invariants | No-op; does not imply data completeness or automation authorization |
| SCHEMA_DRIFT | Wrong shape/index/CHECK/FK/collation, seed conflict, corrupt counters or unsafe missing seed | ZERO repair writes; manual schema/data investigation and separately reviewed repair |
| BLOCKED | Prerequisite/permissions/version/lock/constraint-check/operation failure | Stop; inspect diagnostics and actual state before retry |

Inspect/apply exit0 only for APPLIED_AND_VERIFIED; other inspected states exit2,
execution/connection failure exit1. Any pre-existing incompatible object is detected
before apply makes its first write. A concurrent uncooperative manual DDL/application
writer is outside the lock contract: maintenance/quiescence is REQUIRED, not merely
an argument claiming that the server is stopped.

## Native regression results

MariaDB11.8.6 with synthetic data only:

- Fresh complete chain; fully applied repeat with zero operations.
- Every one of9 statement boundaries across22+23, including first CREATE, tables
  before seeds and daily bucket before reservation ledger. Injected interruption,
  re-inspection, safe resume and verified completion.
- Correct columns but missing CHECK, FK or index: SCHEMA_DRIFT, not auto ALTER.
  The actual SQL defines constraints inside CREATE; a table without them represents
  drift/manual state, not a trustworthy prefix of a successfully completed CREATE.
- Wrong daily immutable policy: refused, value untouched.
- Changed legitimate lifetime used2/max10/revision5 and daily used2/two reservations,
  existing tasks/task history, client/manager bindings and binding audit remain exact.
- Missing lifetime row with operational history: refused, no zero row invented.
- Existing manual task on a partial schema survives resume; disabled consumer creates
  no task; missing budget raises rather than grants permission.
- Separate connections AND two OS processes contend on the same GET_LOCK; the second
  returns MIGRATION_BUSY, first completes, subsequent resume is verified.
- An open caller transaction or FK checks OFF blocks apply. Real HTTP calls to both
  tool files return404. Tests create/drop ONLY their own random isolated test databases.

`tests/analytics_migration_recovery_test.php` exercises these scenarios; no changes
were made to application task/scoring/identity/manager handlers. Existing native
analytics_daily_budget_test.php still passes (Moscow rollover, cap/replay/rollback,
manual exemption). Prior synthetic producer->HTTP->consumer E2E and source policy
remain unchanged; the large180/185-test suites were not repeated without cause.
Final native run:68 assertions, including two HTTP404 checks. Command logs are in the private verification artifacts.

## Future operator procedure — not authorized now

1. Establish verified recovery for PostgreSQL AND MariaDB, approved RPO/RTO, exact server
   code/schema/runtime/disk inventory and a separately approved maintenance window.
   No backup, restart or production access was performed for this change.
2. Stop/quiesce all consumers, producers and migration/application writers using the
   approved service procedure. Keep SHADOW/automation/scheduler disabled. Do not reset
   cursor, drop a table or remove an operational policy row to make verification pass.
3. Use a private authenticated process with explicit ANALYTICS_MIGRATION_DSN,
   ANALYTICS_MIGRATION_USER and ANALYTICS_MIGRATION_PASSWORD. Do not put credentials
   into Git, a README, command history, URLs shared in chat or public CI artifacts.
4. Inspect only: `php tools/analytics_migrate.php --inspect`. This reads metadata,
   bounded invariant checks and takes a transient cooperative lock; no schema/data
   writes. Timeouts10s/metadata lock2s limit waits, not total whole-run duration.
5. Review every state/issue and SQL/contract checksum. A different legitimate server
   collation or MariaDB representation may be blocked intentionally. DO NOT regenerate
   the contract from unknown production state to declare it correct. Review variants
   separately and test them. Only11.8.6 has native coverage here.
6. ONLY after separate migration permission: `php tools/analytics_migrate.php --apply --confirm-maintenance`, capture sanitized stdout/exit status privately.
7. On disconnection/error do not rerun a raw SQL file. Inspect again. Resume only if the
   actual state is a recognized safe partial state. Drift/unsafe seed means STOP and
   a separately reviewed repair plan, not a force flag. No force mode exists.
8. Require APPLIED_AND_VERIFIED, unchanged existing business rows/counters and explicit
   disabled effective flags before application cutover. Successful schema verification
   does not approve priority/confidence, source coverage, identities, manager mappings,
   independent release or live processing. Each gate still requires its own decision.

## Limits

This is migration resumption, not MariaDB rollback/restore. It cannot reconstruct a
lost budget/task/cursor or approve unknown history. Deliberate deletion of all audit
records, uncooperative DDL, power-loss durability and production-size restore are not
proven by these tests. Full referential/data/source completeness is not inferred from
catalog definitions. Real production versions, source currentness/true IDs and exact
rollback artifact still need separate evidence. Overall status remains
BLOCKED — NO VERIFIED RECOVERY.
