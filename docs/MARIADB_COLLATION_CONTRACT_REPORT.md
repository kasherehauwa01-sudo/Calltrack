# MariaDB collation contract correction

Scope: Calltrack, base `feat/analytics-manager-bindings` at `7d3ab45`.
No production connection, migration, configuration change or automation enablement.
This change is not permission to deploy. Overall recovery gate remains
**BLOCKED — NO VERIFIED RECOVERY**.

## Cause

The original physical contract was captured on MariaDB 11.8.6 with an
`utf8mb4_uca1400_ai_ci` database default. Reviewed SQL contains three distinct cases:

1. `ENGINE=InnoDB` without table charset/collation inherits the database default.
2. `DEFAULT CHARSET=utf8mb4` without COLLATE uses MariaDB's **charset default**
   (11.8.6 `character_set_collations`), not simply `collation_database` or
   `collation_connection`. In the verified configurations that default is UCA1400.
3. Explicit table/column collations, including `ascii_bin`, `utf8mb4_bin` and
   `utf8mb4_unicode_ci`, must remain exact.

With server/connection UCA1400 and database unicode_ci the first case explains
the five original mismatches: `analytics_sync_state`,
`analytics_delivery_rejections`, `analytics_task_policy`,
`analytics_task_type_policy`, `analytics_task_policy_events`.
The budget migrations have additional database-inheriting tables, so fixing only
those five existing names would fail after applying the next migrations.

## Restricted contract resolution

Contract version 2 keeps all physical definitions and SQL checksums. A reviewed
`database_collation_tables` list identifies eleven database-inheriting tables,
including the six new budget tables. The list is validated against the CREATE
statements in the checksum-verified SQL files: it must exactly match statements
ending in `ENGINE=InnoDB` without charset/collation options.

Before inspection or apply, read the actual schema default from
`information_schema.SCHEMATA`. Only utf8mb4 with either
`utf8mb4_uca1400_ai_ci` or `utf8mb4_unicode_ci` is supported. The charset default
must remain UCA1400; unsupported defaults are blocked before schema writes.

Resolve the baseline table default and baseline UCA1400 utf8mb4 columns **only
inside those eleven tables** to the selected database default. Numeric columns,
explicit binary columns, charset-only tables, indexes, CHECKs, FKs, engines,
defaults and other metadata are unchanged and compared strictly. A wrong
collation in an otherwise permitted slot is still SCHEMA_DRIFT; the two supported
collations are not interchangeable within a selected profile.

The same resolved contract is used for preflight, concurrent-existing-table
checks and post-creation verification. SQL migrations are unchanged. Existing
tables are neither rebuilt nor altered. No row, policy, quota or cursor is reset.
Application code and flags are unchanged. Tools remain CLI-only.

## Verification

Isolated MariaDB 11.8.6, synthetic data, no production dump:

- Server UCA1400 / database UCA1400 / connection UCA1400.
- Server UCA1400 / database unicode_ci / connection UCA1400 (owner's configuration).
- Original contract reproduces exactly five base-table false positives in the
  second configuration.
- Inspect accepts the base schema as PARTIALLY_APPLIED and creates nothing.
- Apply executes only the nine missing CREATE/seed statements of migrations 22/23.
- Every resulting physical table definition equals the resolved contract;
  existing base definitions and rows remain identical; repeat apply does zero work.
- Wrong table default, text-column collation, explicit binary collation, charset,
  unsupported database/charset default and checksum/manifest tampering are refused.
- Existing migration-recovery regression is exercised under both database defaults:
  partial DDL boundaries, missing CHECK/index/FK, policy drift, counters/ledger,
  preservation of tasks/bindings/history/budgets and concurrent processes.

Results: collation regression **99 assertions**; recovery regression **66 assertions
per profile** (132 total), all passed. PHP lint for all three changed PHP files and
`git diff --check` passed. SQL/API trees are unchanged from the base. The recovery
test's two optional HTTP assertions were not enabled in this CLI-only stand;
web entrypoint guards were not changed by this correction.

Reproduction commands, only inside the disposable internal-network fixture, with
`CALLTRACK_TEST_FIXTURES=1`, explicit isolated
`ANALYTICS_MIGRATION_TEST_DSN=mysql:host=analytics-test-mariadb;dbname=analytics_http_test`
and private synthetic test credentials already configured:

```sh
php tests/analytics_migration_collation_test.php
ANALYTICS_MIGRATION_TEST_COLLATION=utf8mb4_uca1400_ai_ci php tests/analytics_migration_recovery_test.php
ANALYTICS_MIGRATION_TEST_COLLATION=utf8mb4_unicode_ci php tests/analytics_migration_recovery_test.php
php -l tools/analytics_migrate.php
php -l tests/analytics_migration_collation_test.php
php -l tests/analytics_migration_recovery_test.php
```

Tests create and drop only randomly named databases on a dedicated internal test
container. Production configurations and flags are never imported by these tests.

## Future deployment order — separate authorization required

1. Keep all automation/consumers/schedulers disabled. Establish independently
   verified recovery and an approved maintenance procedure first.
2. Review this PR against the actual server code and confirm the exact SQL and
   contract SHA-256 values. Do not copy a freshly captured production schema into
   the contract to bypass drift. Do not ALTER existing tables to satisfy it.
3. Deploy only the reviewed tooling/contract after approval. Ship loader and JSON
   together: v1/v2 mismatches intentionally fail closed. Use the existing
   private credential mechanism; never put credentials in Git, shell history or
   public diagnostics. This task does not execute that deployment.
4. Read-only SQL for configuration corroboration:

   ```sql
   SELECT VERSION(), @@collation_server, @@collation_database,
          @@collation_connection, @@character_set_collations;
   SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
   FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE();
   SELECT CHARACTER_SET_NAME, DEFAULT_COLLATE_NAME
   FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME='utf8mb4';
   ```

5. Run `php tools/analytics_migrate.php --inspect` using the reviewed tooling.
   This performs metadata/invariant reads and a temporary cooperative lock,
   not schema/data changes. Expected before budget migrations: PARTIALLY_APPLIED,
   first three files verified, both budget files NOT_APPLIED, no drift issues.
   A nonzero inspect exit status (2) is expected while not fully applied.
6. Stop on any drift, unsupported profile, unsafe seed or unexpected existing
   budget/history. Do not change sorting defaults, delete rows or force the tool.
7. Only with **separate explicit permission for working migrations**, verified
   recovery and quiesced writers: invoke the documented explicit apply/resume
   procedure in MARIADB_MIGRATION_RECOVERY_REPORT.md. Never blindly rerun raw SQL.
8. Require APPLIED_AND_VERIFIED and a second no-op inspection. Confirm old rows,
   budgets and disabled flags. Successful schema verification does not authorize
   CHURN_RISK, SHADOW, release, scheduler or automatic tasks.

Reverting tooling files does not undo committed MariaDB DDL. Unknown historical
sort changes, mixed unreviewed collations, changed charset defaults and concurrent
uncooperative DDL remain stop conditions. There is no automatic table conversion
or generic "ignore collation" switch.
