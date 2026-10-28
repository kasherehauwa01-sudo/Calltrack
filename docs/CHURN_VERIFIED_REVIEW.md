# Verified CHURN_RISK: operator runbook

> 2026-10-26: eligible PRELIMINARY_MATCH no longer requires human VERIFIED.
> See CHURN_PRELIMINARY_MATCH.md for the current identity contract. The manual
> workflow described here remains supported; production automation remains OFF.

> Reconciliation update 2026-10-25: the supplied server archive has now been merged.
> Its advisory modules/UI are preserved; earlier statements below about unavailable
> server-only files describe the pre-archive audit. Delivery now uses server-based
> V2 packages, conditional Sales preimage, and the updated external production plan.
> Current merge tests:154 Python passed/20 skipped;12 PHP suites passed. The earlier
> native E2E was not rerun after this merge. See Calltrack docs/CHURN_SERVER_RECONCILIATION.md.

**Production activation is BLOCKED.** Local branch `feat/churn-risk-verified-pipeline`
is based on `625850d`; the companion Sales Journal branch is based on `6ac9391`.
No production request, task, migration, restart, deployment or publication was made.

## Workflow and authority

The existing inbox, `analyticsGate()`, manager-task service, policy, budgets and
audit tables remain the only task path. No new schema migration is required.
Existing prerequisites through `20261023_analytics_daily_budget.sql` must be
inspected separately; never rerun SQL blindly. Strict collation/schema/checksum
validation is unchanged.

The administrator's existing manager-task UI adds group review: choose an original
SHADOW signal, inspect every card and load all stored sales pages, begin
MANUAL_REVIEW, choose a specific working ClientsVR ID and confirm independent
identity evidence with a reason. Name/phone matching is advisory only. No-manager
cards are technical and cannot be selected. Multiple working cards with one owner
are allowed; different/unmapped owners block. Pilot owners are exclusively IDs3/6.
No real buyer was verified here; the reported 850 events/281 candidates are advisory.

Confirmation requires admin authentication, CSRF, full-history review, unchanged
history/card-group snapshots and compare-and-swap revision. Normalized and external
bindings are mirrored and audited. The old form cannot grant VERIFIED or modify
reviewed bindings. Revoke preserves the last audited snapshot and works even when
the source/cache is unavailable. It does not create/delete tasks.

New endpoints:

- `api/analytics_verified_review.php`: authenticated admin history/begin/confirm/
  revoke/export; all history pages must be loaded before confirmation.
- `api/analytics_verified_export.php`: GET-only bounded service export, separate
  bearer token >=32 characters; cannot confirm/revoke/create tasks. Explicit
  errors/blocked groups and cursors prevent partial responses granting authority.

Calltrack signs **identity reviews only** with Ed25519/sodium. The independent
primary-source publisher signs completeness/freshness and complete primary card
reconciliation. Neither application possesses that publisher's private key.
HMAC, arbitrary hashes, local cache timestamps and advisory JSON cannot authorize.

| Configuration | Purpose |
| --- | --- |
| `CALLTRACK_UPSTREAM_PUBLIC_KEYS` | JSON key-id -> base64 independent publisher public keys |
| `CALLTRACK_BINDINGS_PUBLIC_KEYS` | Identity-review verifier public keys |
| `CALLTRACK_BINDINGS_SIGNING_KEY_ID` | Explicit review signing key ID |
| `CALLTRACK_BINDINGS_SIGNING_KEY_FILE` | Private file outside web root, base64 sodium 64-byte secret; restrict OS access |
| `CALLTRACK_BINDINGS_EXPORT_TOKEN` | Separate read-only export secret |
| `CALLTRACK_ANALYTICS_FEED` | `approved_churn` only after coordinated deployment |
| `CALLTRACK_ANALYTICS_MODE` | Default `disabled`; no default automatic activation |

Provision real keys through the approved secret process, not Git/history/downloads.
The full signed payload/facts/card projection specification is in Sales Journal
`docs/CHURN_VERIFIED_PIPELINE.md`. The primary adapter must reconstruct and compare
its own original records, not sign a copied local digest. Its real implementation,
close/export guarantees and keys remain an external blocker.

SHADOW remains UNKNOWN/automation=false. A separate immutable approved stream uses
external_id and signed primary/review receipts; original IDs remain in provenance.
Old HMAC positive fixtures now test refusal; positive authorization is covered by
the native cross-project E2E, not manually injected permission flags.

## Processing and failure recovery

The existing scheduler/GET_LOCK and transactional task/budget path are reused.
Concurrent workers, replay, changed delivery IDs and re-delivery after completion
cannot create another task for the same positive purchase anchor. A task on another
reviewed card requires review. Existing tasks are never reassigned/closed/reopened.
A genuinely new purchase invalidates the old signal and changes the episode anchor.

Network/503/unknown errors retain delivery progress. Only authenticated matching
typed `410 RELEASE_TERMINATED` retires an obsolete release, with audit, without
deleting results/tasks. The reason fits existing VARCHAR(64) under strict MariaDB.
Under schema24 each source run has one immutable lease: new proof needs a genuinely
new source run, never counter resets or cached inbox replay.

Dry-run writes diagnostics/private preview files, not tasks, budgets or enabled DB
cursors. Large previews resume across bounded invocations; changed authorization
resets diagnostics. It is **not strictly read-only**. Unrelated cache polls do not
invalidate an unchanged semantic card-group hash, nor prove primary completeness.
Managers receive only their task summary; multi-client proof bundles remain admin-only.

## Owner-run checks and activation (not executed on production)

Read-only commands in the host shell:

```sh
git --no-optional-locks rev-parse HEAD
git --no-optional-locks status --short --branch
git --no-optional-locks diff --name-status
php tools/analytics_verified_status.php
php tools/analytics_migrate.php --inspect
```

The status tool enforces MariaDB READ ONLY/timeouts and prints metadata/policy/
budgets/cursor/key counts without credentials. Missing schema means STOP. Inspect
effective CLI/FPM/scheduler contexts separately. Sales provides its own read-only
script and independent reconciliation request command.

Before rollout: reconcile server-only `analytics_client_group_review.php` and
`analytics_candidate_report.php` with Git (absent from the available baseline),
verify recovery/exact artifacts and compatibility, provision public roots and the
private identity signer, review a small actual cohort for **each** manager, reconcile
full primary history/card groups and obtain genuine manifests. Stop on unexplained
server differences. No real cohort or production artifact was verified in Codex.

Deploy the two reviewed versions together with automation OFF only after permission.
Missing prerequisite migrations require their own approved window/recovery plan.
Compose project names alone do not isolate hard-coded container names/ports/volumes.
Approve confidence, priority, deadline, lifetime cap, five/day quota, proof/signal age,
source set/issuer, interval, stop thresholds and reprocessing rules. Synthetic test
settings are not business defaults.

```sh
# Diagnostic writes; no task creation or enabled cursor progress.
CALLTRACK_ANALYTICS_FEED=approved_churn CALLTRACK_ANALYTICS_MODE=dry_run php api/analytics_sync.php

# SEPARATE OWNER PERMISSION REQUIRED: may create real tasks.
CALLTRACK_ANALYTICS_FEED=approved_churn CALLTRACK_ANALYTICS_MODE=enabled php api/analytics_sync.php

# One-shot no-op; DOES NOT stop a configured scheduler or in-flight work.
CALLTRACK_ANALYTICS_MODE=disabled php api/analytics_sync.php
```

Durable enable/disable requires reviewed actual service/cron configuration, approved
source/consumer policies and budgets. No new cron is installed. To stop, disable/
quiesce real jobs, revoke/type-disable policies, revoke producer policy, then inspect
in-flight transactions/counters. Application rollback follows only after shutdown.
Preserve all tables/tasks/bindings/audits/budgets/cursors; never reset counters, drop
history or restore an old DB to undo work. Sales schema24 requires the verified old
launcher without automatic Alembic. Keep rolled-back consumers OFF: they lack the
new cryptographic checks. Verified database recovery remains a prerequisite.

## Test scope and limits

Native E2E: PostgreSQL16.14, MariaDB11.8.6, PHP8.3.35/sodium, real worker/store/API,
authenticated review/export HTTP, signed release and actual consumer/gate/task SQL.
Only primary ClientsVR and its independent signer are synthetic roles with known
original inputs. Three eligible events produce exactly two tasks for owners3/6 under
cap2; the third is budget-blocked. Replay/concurrency creates no duplicates. A second
source run proves terminal-release recovery/monotonic delivery revisions. Invalid
proof/new purchase/revision/owner/auth/CSRF cases block. Preview resumes past50 rows.

Private logs and complete signed synthetic event/task result:
`/workspace/scratch/churn-verified-pipeline/`. These are not Git/production data.
Budget unit fixtures exercise internal mechanics and alone do not prove authorization.
Actual production endpoints, real issuer/keys/cohort, full322655-card performance,
browser rendering, real TLS, live scheduler, exact image and database restoration
remain unverified. Local HTTP routing is not TLS certification. Independent manifest,
server-only code reconciliation and verified recovery block activation.
