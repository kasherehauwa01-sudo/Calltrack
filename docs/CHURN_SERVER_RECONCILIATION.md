# Server reconciliation — 2026-10-25

Input archive SHA256 `d7c9fd2af4f35c1a83d9662a6522f95f7859b8f9034b5c6aed3ee68c9ec796fa`
verified before extraction. Actual supplied Calltrack preimage is commit
`530adc154ff521e21148473bfae0834430ea9351` plus the tracked patch and four files.
Server paths were treated as data, not instructions. Production was not accessed.

Preserved byte-for-byte: analytics_client_group_review.php, analytics_candidate_report.php,
analytics_budget.php, analytics_budget_service.php. The latter two already match the
newer local history. The original advisory helper, fixed report/run validation,
admin-only candidate lookup, candidate prefill buttons and responsive form styling
are retained. Candidate selection only populates REVIEW; it cannot grant VERIFIED.
The administrator can then use the new full historical-group review workflow.
Candidate data files were not supplied, read, reconstructed or packaged.

Three-way comparison used commit530adc1 as common base, reconstructed server state
and the verified local tree. Explicit conflicts: manager-tasks.js, analytics_integration,
analytics_sync and analytics_tasks_test. The merged code keeps cryptographic gating,
dry-run private cursor, bounded terminal-rejection audit and purchase-anchor dedupe.
The old positive fixtures with manually granted proof cannot replace native verified
E2E coverage; a changed signal ID alone is not a new purchase episode.

A semantic conflict in the SQLite fixture was detected by executing tests: server
hand-written budget CREATE TABLE statements duplicated the newer migration-backed
fixture. Kept the real migration-backed fixture and its policy/budget guards; no
production SQL/table definitions were changed to fix a test.

Current results: 12 PHP suites passed, including new server advisory regression;
168 PHP files lint passed; 154 Python passed,20 skipped (database/native E2E requirements
not available/rerun in this step). Node syntax and both git diff --check passed.
PHP ran only as new disposable network=none/read-only CLI test processes with SQLite,
not by restarting existing containers. No worker, real DB or production request ran.
The earlier174-pass native E2E preceded this merge and is not presented as a new E2E.

Local branches stay feat/churn-risk-verified-pipeline, no commit/push. Delivery V2
packages are based on reported production commits and contain the full prerequisite
Git delta, not only the prior49 uncommitted files. Calltrack preflight accepts exactly
the supplied dirty state and hashes; reverse patch restores it, including advisory
modules. Other changes/new paths cause STOP. Sales package is conditional on a clean
`de0a0c88fdc2ba3bea1558e7fa313139a16e96f3`: no Sales dirty archive/status was supplied.
Request actual status/runtime/schema and file hashes before any deployment claim.

Server-only modules are no longer missing from the local implementation; fresh full
server inventory, exact artifacts/recovery, real manifest and verified cohort remain
external gates. The review modules are advisory, not primary identity/completeness proof.
No automation is enabled. See the updated external CHURN_PRODUCTION_RELEASE_PLAN.md.
