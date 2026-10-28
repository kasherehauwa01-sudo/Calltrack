# CHURN_RISK PRELIMINARY_MATCH — отчёт 26.10.2026

Новое бизнес-правило реализовано в локальных feat/churn-risk-verified-pipeline.
Автоматическое назначение больше не требует ручного VERIFIED для допустимого
предварительного сопоставления. Статус/неопределённость не подменяются: REVIEW+
PRELIMINARY_MATCH в Calltrack, PRELIMINARY_MATCH в source identity, manual_verified=false,
reviewer/actor0 только для системного matching, ручные review-флаги false.

## Проверки (фактические)

- 187 Python passed,0 skipped,2 DeprecationWarning websockets. Включены freshness,
  STORED_SALES, INACTIVITY, delivery, pilot, Ed25519, import/export и новый matcher.
- 13 PHP suites passed; новый matcher17 утверждений, ручной review25, terminal22.
- PHP lint171 файлов, Python AST, Node syntax, git diff --check обеих веток PASS.
- PostgreSQL16.14 -> actual worker/store/FastAPI -> signed Calltrack binding ->
  independent synthetic primary manifest -> approved external_id -> real HTTP/consumer/
  gate -> MariaDB11.8.6 tasks: оба E2E параметра (ручной и предварительный) PASS.
- В предварительном E2E0 VERIFIED bindings, без человеческих confirm:2 задания для
  managers3/6, третье блокируется cap2. Несколько карточек одного покупателя/owner
  представлены одним заданием по явно designated primary. Два конкурентных обработчика
  с РАЗНЫМИ signal IDs на одну группу создают ровно1 задачу. Повтор/новый release/
  доставка послеDONE не дублируют эпизод. Proof/assignment/metadata tampering, новая
  покупка, чужой менеджер/версия, SHADOW/disabled, отсутствие подписи блокируются.
- Отдельно native MariaDB race:8 workers -> максимум5/day, replay/rollback/corruption
  checks PASS. Это проверяет транзакционную квоту, не заменяет signed E2E.
- CLI подготовки dry-run без binding/task writes, apply сохраняет REVIEW, idempotent,
  неоднозначность/разные owners/отсутствующая карточка/manager/technical/aggregate/
  неполные metadata блокируются. Ручной begin/confirm после preliminary работает;
  последующий auto-preparation не перезаписывает admin VERIFIED/revocation.

Полные приватные результаты: /workspace/scratch/churn-preliminary-20261026/
regression-tests.log, php-regressions.log, daily-concurrency.log и
preliminary-e2e-result.json (полное настоящее тестовое signed событие/результаты).
Подписант и первичный ClientsVR в E2E — отдельные синтетические роли с собственными
известными исходными данными, не production издатель/manifest. Проверены факты и полные
проекции карт перед подписью, не вручную введённый approved-event/allowed=true.
Внутренние budget unit fixtures отдельно используют trusted контекст для проверки
счётчиков; они сами по себе не доказательство автоматического разрешения.

## Риски и пределы

Name-only unique-card match намеренно остаётся предварительным и может ошибиться,
например, при устаревшем каталоге/одинаковых именах. Поэтому сохраняются короткие signed
leases, независимая свежесть/полнота, owner mapping, classification, source revision,
полный снимок группы и last-positive anchor. Multi-card группы нельзя выводить только
из имени/телефона/owner. Нужны authoritative customer ID и explicit primary ID.
Если stable identity присутствует и у singleton, используется её group key с explicit
primary, не card fallback. Кросс-именные aliases без полной первичной сверки остаются
coverage blocker; fuzzy объединения исторических продаж не выполняются.

Текущий ClientsVR/report contract не подтверждает поля analytics_customer_kind,
analytics_customer_identity, analytics_primary_client_id. Они реализованы как явный
входной контракт, отсутствие/конфликт блокируют назначение. Не создавать их из имён/
телефонов и не утверждать, что281 кандидатов прошли. Для настоящего запуска нужны
реальные primary exporter/manifest/keys с matching_policy=preliminary-customer-group/1,
valid complete-through/revisions/facts и full card reconciliation. Историческое имя
reviewed_count в протоколе означает signed assignable identities; отдельно доступны
preliminary_count и manually_verified_count. Ручной review не симулируется.

Политики approved0/CHURN disabled, feature flags и production не менялись. Менеджеры
pilot3/6 и their aliases должны однозначно разрешаться existing binding service.
Настройки confidence/priority/due/lifetime cap, five/day, refresh/stop rules требуют
реального approval. Подготовка CLI — не разрешение source release/tasks. Общий
primary manifest missing/stale может блокировать всю партию; это не трактуется пустой
полной выборкой. Обновление группы требует повторной подготовки/независимой сверки,
а admin/revoked решения автоматически не заменяются.

Не проверены real restore, exact production image rollback, live TLS/browser rendering,
полный объём322655 записей, реальные buyer classification/coverage и actual schedulers.
Тесты не доказывают production readiness. Production automation BLOCKED до закрытия
внешних доказательств и отдельного разрешения; staged rollout также имеет prerequisites.

## Пакеты и безопасная установка

Новые V3 пакеты относительно production Salesde0a0c8 и Calltrack530adc1+переданного
архива серверных правок; включают прежние согласованные изменения/бюджеты/advisory UI.
Никаких reset/clean/force/3way. Preflight требует точных preimage hashes и dirty paths;
reverse восстанавливает именно supplied server state. Если V2 уже установлен, V3
поверх него не применять: нужен другой delta. PostgreSQL24 уже сообщён: не повторять
upgrade. Новой migration для preliminary нет, REVIEW/evidence поля используются без
изменения SQL/checks/schema_contract. Предварительный статус PostgreSQL varchar уже
поддерживает. Сохранены external rollback launcher и весь аудит/задачи/квоты.

См. CHURN_PRELIMINARY_MATCH.md в обоих проектах и внешний обновлённый
CHURN_PRODUCTION_RELEASE_PLAN.md: backup/verified restore, staged exact-image checks,
OFF установка, dry-run preparation, отдельное apply matching, реальные подписи,
approved pilot и отдельное enabling. Откат начинается с quiesce/disable/revoke,
не сброса БД/счётчиков; старый verified-only consumer остаётсяOFF.

Code changes local only. No commit/push/deploy, real DB/tasks/VERIFIED or source proof.
Тестовые контейнеры принадлежат изолированному стенду; production контейнеры не трогались.

## Изменения относительно предыдущих согласованных target-пакетов

### sales-journal

- `README.md`
- `backend/app/routers/analytics_delivery.py`
- `backend/app/services/analytics_automation.py`
- `backend/app/services/analytics_evidence_request.py`
- `backend/app/services/analytics_preliminary.py`
- `backend/app/services/analytics_verified_bindings.py`
- `backend/tests/test_preliminary_matching.py`
- `backend/tests/test_verified_pipeline_e2e.py`
- `docs/CHURN_PRELIMINARY_MATCH.md`
- `docs/CHURN_VERIFIED_PIPELINE.md`

### calltrack

- `README.md`
- `analizmop/manager-tasks.js`
- `api/analytics_integration.php`
- `api/analytics_preliminary.php`
- `api/analytics_sync.php`
- `api/analytics_verified_approval.php`
- `api/analytics_verified_bindings.php`
- `api/analytics_verified_export.php`
- `api/analytics_verified_review.php`
- `api/manager_tasks_service.php`
- `docs/CHURN_PRELIMINARY_MATCH.md`
- `docs/CHURN_VERIFIED_REVIEW.md`
- `tests/analytics_preliminary_test.php`
- `tests/verified_pipeline_e2e.php`
- `tools/analytics_preliminary.php`
