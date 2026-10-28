# CHURN_RISK pilot daily budget

PRODUCTION DEPLOYMENT = BLOCKED — NO VERIFIED RECOVERY.

Policy `aviator-churn-pilot/1`: five new automatic CHURN_RISK tasks per Moscow
calendar date, shared by all managers. No cron reset. The existing explicitly
approved lifetime budget is still required and consumed in the same transaction.
Manual tasks do not consume either budget. PURCHASE_OVERDUE remains prohibited
by the existing phase-1 gate. No confidence, priority or automation approval is
seeded by this change. NORMAL remains a proposal, not an approved default.

Apply ONLY with separate deployment permission:
`database/20261023_analytics_daily_budget.sql`, after
`database/20261022_analytics_budget.sql` and all its prerequisites. It adds:

- analytics_daily_budget_policy: fixed version/timezone/cap contract, not enablement.
- analytics_daily_budget: date-keyed counter, no resetting old dates.
- analytics_daily_budget_reservations: unique signal-key ledger, FK protects bucket
  deletion and allows counter/ledger consistency checks and diagnostic audit.

MariaDB DDL implicitly commits. A partial/error application is a STOP condition;
do not retry blindly or enable a consumer with a partially applied schema. This
migration does not modify client/sales data, bindings or existing task policy.

Persisted task policy, lifetime budget, daily policy and counter are locked in the
same transaction as task/slot/history creation. Lifetime lock serializes automatic
creators; daily reservation uses a conditional UPDATE plus unique ledger entry.
Task failure rolls all reservations back. Existing task/slot checks precede spending;
normal receipt replay also retains the existing task. Missing policy/table, invalid
policy/counter, counter/ledger mismatch and SQL errors block new tasks. Missing
bucket on the first use of a new date is legitimate; FK-protected used buckets
cannot be silently deleted. No application endpoint resets quota or ledger.
Direct operator deletion of all audit records is outside this integrity boundary.

Creation timestamp is frozen in UTC after budget locks. That same instant selects
the Moscow bucket and sets explicit created_at; an insert crossing midnight cannot
be charged to another day than its recorded creation. For pilot-generator events,
persisted CHURN_RISK due_days must equal 3. Due_at is calculated by adding three
calendar days in Europe/Moscow and storing UTC. Other generators/task types keep
their existing due-date behavior. Dry-run never reserves quota or creates tasks;
it can write normal diagnostic inbox/cursor state and report exhausted/unavailable
quota. A blocked receipt is not automatically replayed the following day: use a
new fresh independently approved release or separately reviewed reprocessing flow.
Never reset a production cursor to retry it.

## Tests

analytics_daily_budget_test.php: Moscow midnight, next date, five tasks, replay,
rollback, multiple managers, manual exemption, missing policy. Runs with SQLite
and native MariaDB. analytics_daily_concurrency_test.php: eight native MariaDB
writers, five tasks, replay, insert-failure rollback, three-day due dates and
counter corruption. churn_pilot_e2e.php is driven by the Sales native PostgreSQL
fixture and independently signed synthetic release over HTTP: six eligible clients,
SHADOW/dry-run zero tasks, concurrent enabled consumers five globally, sixth blocked,
correct managers, no duplicate and due+3. No production approvals are forged.

## Deployment/rollback gate

Do not deploy until verified recovery is available and the owner separately permits
server changes. Compare actual deployed Git/schema/config first. Keep producer and
consumer automation disabled; apply reviewed SQL once, inspect all three tables,
constraints and indexes, then install reviewed code with flags still disabled.
Perform synthetic staging checks and review a fresh dry-run before any separately
authorized real release. Approve confidence, priority, exact scope/generator, source
proof TTL/registry, lifetime cap, stop criteria and reprocessing rules explicitly.
The accepted daily cap and due_days alone do not grant permission to create tasks.

On any quota or policy inconsistency: stop automatic consumers and producer release,
retain diagnostics, tasks, both budgets, cursors and ledger. Code rollback must keep
automation OFF: old code does not enforce the new daily quota. Do not drop/downgrade
budget history, reset used counters or replay cursors. No database recovery rehearsal
or identical production-image recovery is established by these isolated tests.
