# CHURN_RISK phase 1 — Calltrack

Локальная ветка `feat/churn-risk-automation-phase1`. Разработка выполнена без production deploy/enable; публикация изменений через GitHub выполняется отдельным подготовительным этапом.

Полный протокол, signer/source assumptions, тесты и rollout/rollback описаны в Sales Journal `docs/churn-risk-phase1.md` (отдельный репозиторий). Технический E2E: настоящий PostgreSQL worker и native producer → настоящий HTTP → Calltrack/MariaDB → одна задача под конкурентной нагрузкой, без переписывания SHADOW event в тесте.

## Закрытые defaults

- `CALLTRACK_ANALYTICS_MODE` = disabled по умолчанию; `CALLTRACK_ANALYTICS_FEED` = shadow.
- `approved_churn` выбирает отдельные `/analytics/automation/…` endpoints и `analytics_automation_sync_state`, не использует старый shadow cursor.
- Фаза 1 допускает исключительно CHURN_RISK. PURCHASE_OVERDUE остаётся распознаваемым для диагностики, но всегда PHASE1_CHURN_ONLY для создания задач, даже при ошибочной business policy.
- Native approval требует external_id, reviewed binding revision, independent source proof method/hash/revision/expiry и expected client/manager assignment. Нет phone/name matching для автоматических задач.
- Производственная бизнес-политика не утверждена. Тестовые пороги/сроки не являются defaults.

## Бюджет

Согласованная additive migration `database/20261022_analytics_budget.sql` создаёт `analytics_task_budget`, `analytics_task_budget_events`, отдельный approved feed cursor. **На production не запускалась.**

Budget default: max_tasks=NULL, approved=0, used_tasks=0. Предложение 10 не активировано. Это lifetime cap новых автоматических задач в БД, без reset по run/дням/restart/DONE. Ручные задачи от него независимы.

Admin API: `GET /vr/calltrack/api/analytics_budget.php` возвращает budget. `POST` с действующими session+CSRF и `{revision,approved,max_tasks}` меняет cap/approval с CAS и аудитом. Не позволяет обнулить used_tasks, уменьшить max ниже уже потраченного или обойти admin role. Примеров с production approved=true в документации намеренно нет: число и включение требуют отдельного решения владельца.

Новая automatic task резервирует budget под FOR UPDATE и условным UPDATE в той же транзакции, что task/slot/inbox. Дубликат не расходует budget; rollback task insertion откатывает расход. Missing/unapproved/exhausted/read failure блокирует создание и оставляет receipt с причиной. Связанные существующие задачи при replay не требуют нового budget и не открываются заново после DONE.

Рост cap не запускает все старые blocked receipts автоматически. Нужны новый preview/re-evaluation и проверка свежести; UI dry-run только диагностирует и не создаёт задач.

## Проверки

`tests/churn_automation_e2e.php` требует строго guarded MariaDB `analytics-test-mariadb/analytics_sync_test` и настоящий локальный producer HTTP; не принимает production DSN. Вызывается из Sales Python E2E. Три процесса обрабатывают одинаковые/разные сигналы при cap1; ровно одна задача/один расход, верный manager; HTTP replay, injected network interruption, реальный MariaDB partial-receipt rollback и retry; DONE/history; other types, SHADOW и budget failure закрыты.

`tests/analytics_budget_test.php` отдельно проверяет NULL defaults, approval/revoke, duplicate/no spend, SQL insertion rollback, запрет reset. SQLite tests не выдаются за concurrency proof — конкурентный тест выполняется на MariaDB. `tests/fixtures/analytics/phase1_event.php` служит только старым consumer unit tests, никогда не используется главным native E2E или production.

Сохранено исправление прошлого аудита `analytics_sync.php`: malformed items/cursor не трактуются как пустой успех/конец run, cursor проверяется до receipt effects. Невалидное событие карантинируется, malformed page/410 останавливает синхронизацию с сохранённым checkpoint.

## Внедрение/остановка

Только после отдельного разрешения: сверить расходящуюся server ветку и schema → backup → disabled consumer → additive SQL migration → deploy disabled → health/ACL/defaults → реальные source/identity/manager/business approvals → согласованный preview/dry_run → явный cap/approval и ограниченный pilot. Не делать blind pull/force push и не сбрасывать cursors при 410.

Stop: остановить scheduler и mode disabled; отозвать DB task policy/budget и producer release policy. Сохранить tasks, results, history, counters, inbox/cursors. Code rollback с additive schema retained; destructive downgrade и массовая отмена задач без проверки запрещены. Уже committed task не исчезает при переключении флага.
