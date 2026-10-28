# Calltrack: повторный CHURN_RISK production audit

22.10.2026, только изолированная Codex-среда. База ветки audit/churn-risk-production-readiness — 185bc0a4f16d771327fc9365c280e5bcc276ae6f (PR504); дерево совпадает с c05b6de. Production не запрашивался.

**HIGH, исправлено:** createManagerTask доверял internal automatic.allowed=true и budget без собственной persisted task-policy проверки. Основной processAnalyticsEvent уже имел проверку, HTTP manual API не принимал automatic, но ошибочный прямой вызов мог обойти unapproved policy. Добавлен guard под transaction/policy lock: approved policy + enabled CHURN_RISK + ACTIVE approved stable client/manager assignment. Полный source/identity/freshness gate по-прежнему обязан выполняться processAnalyticsEvent; primitive не является публичным API и не защищает от произвольного PHP/SQL с доверенными credentials. Manual admin tasks остаются независимы от автоматизации.

Изменения:
- api/manager_tasks_service.php: повторная persisted policy/assignment проверка перед вставкой и до расхода budget.
- tests/analytics_budget_test.php: прямой вызов с approved budget/unapproved policy, disabled type, zero tasks/spend; прежние replay/rollback/revoke проверки.
- tests/churn_automation_e2e.php: реальные MariaDB отрицательные сценарии disabled, unapproved, missing client/manager, stale sales, UNKNOWN, disabled type, zero tasks/spend до конкурентной разрешённой вставки.

Повторно: 7 PHP scripts PASS, syntax PASS, Node category test PASS; actual PostgreSQL worker -> native signed release -> HTTP -> MariaDB11.8.6 -> 3 concurrent processes, cap1, правильный manager, retry/partial failure/cursor, DONE/history PASS. Полный Python набор и version-specific результаты с точными командами: Sales docs/audits/churn-risk-test-results-20261022.md.

**PASS:** isolated policy/gates/budget/concurrency/replay/ACL. **BLOCKED:** production source completeness/freshness/stable IDs/full history/manager mapping; disabled installation без existing recovery/preflight; real activation/business approvals. Тестовый synthetic manifest не подтверждает реальные продажи.

Все пути создания: authenticated+CSRF manual manager_tasks.php (admin only), approved CLI consumer -> processAnalyticsEvent -> createManagerTask, trusted internal PHP calls. Consumer disabled не делает запросы; dry_run пишет inbox/diagnostics/cursors и не является строгим read-only. DB policy блокирует automatic даже при переданном enabled; budget lifetime без reset. Shared PG/MariaDB transaction нет: уже fetched event может обрабатываться до следующей producer revocation проверки в пределах TTL. Stop требует локальной consumer policy/budget revoke и ожидания in-flight transactions; не обещать мгновенного отзыва и не удалять уже committed задачи.

Миграции: старые 3 SQL не повторять, если 9 reported таблиц уже fully match. Новые analytics_task_budget, analytics_task_budget_events, analytics_automation_sync_state — только после отдельного разрешения; implicit DDL commits, partial => STOP, no --force/retry/drop. Default budget NULL/approved0/used0, cursor separate0. Rollback кода disabled сохраняет эти таблицы/задачи/audit/counters. SQL не менялся этим аудитом.

Runbook A/B/C/D и rollback: Sales docs/audits/churn-risk-production-readiness-20261022.md. Read-only команды production: docs/churn-risk-production-readonly.md. A — metadata/git/runtime/диск без изменений. B — только после отдельного разрешения + proven existing recovery + disk + source/server code сверки, disabled. C — только read-only metadata и проверка отсутствия новых задач относительно baseline. D — новое явное разрешение, независимые source/identity evidence, business params/budget. Пользователь отказался от новых production backup; их не создавать. Это не разрешение на миграции без recovery.

Calltrack release переключение пока не привязано к фактическому серверному PHP deployment и server-only/untracked файлам: BLOCKED до run-sheet владельца. Не выполнять blind pull/reset/rsync с удалением. Main, flags, production DB/container не менялись.
