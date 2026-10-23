# Preparing client review without automatic tasks

Use the existing manager-binding and analytical-signals admin interfaces.
No second binding system or new UI is needed.

1. Under separately authorized diagnostic operation, receive native SHADOW
   publications with the existing consumer in `dry_run`, feed `shadow`.
   This writes inbox/diagnostics/cursors and therefore is not read-only.
2. The existing inbox exposes the client-review form. Require an actual unique
   ClientsVR ID, complete scoped history, explicit technical classification,
   and evidence. REVIEW/AMBIGUOUS/NOT_FOUND are not grants.
3. Map source manager designations through the existing admin manager-binding
   interface to active manager users. A textual designation alone is not a
   verified Calltrack assignment.
4. The existing Sales producer identity-review CLI separately requires its
   reviewed identity/history evidence. It does not read or silently trust the
   Calltrack DB. No automatic cross-DB approval transfer is implemented.
5. An independently authorized release has `subject.kind=external_id`, unlike
   the normalized-name SHADOW subject. Its existing consumer binding is
   explicitly reviewed in the same admin UI. A SHADOW review never promotes
   the signal or silently grants approval to this different subject key.
6. Saving bindings and pressing admin dry-run always use diagnostic processing;
   even ready signals do not create a task through that interface.

Dry-run consumes feed progress. Merely switching the consumer to enabled does
not replay the same publication. Prefer the first *new, fresh, independently
approved* release after reviews. Never reset cursors or flip stored SHADOW
payloads. The trusted processing service can re-evaluate an existing approved
receipt, but there is no reviewed general-purpose production replay UI/CLI;
any future operator replay needs explicit authorization and fresh producer
validation, not ad hoc SQL or unchecked payload replay.

## Validation fix

`api/analytics_admin.php` previously accepted VERIFIED with a different
`client_id` for an external-ID event, if that other client existed in cache.
The task gate rejected the mismatch, so this did not authorize a wrong task,
but the saved VERIFIED state was contradictory. The handler now returns422
before any binding/audit write. Normalized-name manual review is unchanged.

The companion Sales test `test_client_review_bootstrap.py` executes the real
admin endpoints with sessions/CSRF against synthetic MariaDB and native Sales
PostgreSQL/HTTP events. Before the fix the mismatch returned200; after it
returns422 without a new binding. Missing full-history approval is rejected;
manager-only access is denied; disabled processing and diagnostics create0
tasks; explicit test-only authorizations create exactly1 correctly assigned
task and replay returns the existing task.

`tests/analytics_review_bootstrap.php` is only a guarded isolated-test helper.
It refuses execution unless fixture mode and the named test DB are selected.
It is not a new production replay endpoint or binding mechanism.

No production mappings, data, policy, migration or feature flag is changed.
Production deployment still requires verified recovery and separate approval;
source completeness and real client/manager identities are not proven by tests.
