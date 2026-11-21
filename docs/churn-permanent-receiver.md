# Постоянный приёмник churn: аудит, контракт, установка и откат

Статус: draft для review, не разрешение на создание реальных задач.
Production, задачи, БД, ключи, storage и расписания в этой работе не изменяются.

## База и границы проверки

Указанная ветка `production/max-message-sync` на GitHub — `715be8462deaf270c0729310abab25961f91772a`;
перечисленных analytics/task модулей в ней нет. Указанный фактический Production-коммит
`cfbab96539bbcadcf1963991f77c10ccc78ce5a8` совпадает с текущим GitHub `main` и содержит их.
Предлагаемая ветка `feat/churn-permanent-receiver` основана на cfbab96, PR направлен в main.
Это осознанно указанное расхождение с первоначальным названием исходной ветки;
перенос полной подсистемы задач в устаревшую ветку не выполняется.

Рабочий API: `/var/www/html/vr/calltrack-v3-20261026/api/`.
Git checkout `/var/www/html/vr/calltrack/` не считается работающим web root.

В Sales Journal изучены реальные `backend/scripts/run_churn_automation.py`,
`churn_trusted_ftp.py`, `collect_churn_status.py`, `app/churn_automation.py`,
`services/churn_schedule.py`, `churn_receiver_evidence.py`, `churn_ftp_context.py`
и companion package `deploy/churn-permanent/calltrack/`.
Receiver/budget/schema перенесены из этого существующего протокола с дополнительными проверками,
а не реализованы как параллельная система. Репозиторий Sales Journal не изменяется.

Фактический production manager_tasks_service.php с soft delete/reopen недоступен.
Его содержимое, схемы и API не восстанавливаются по предположениям.
Установка Git-файла целиком поверх рабочего запрещена.
Installer применяет только проверенный точечный patch и отказывается от неизвестных SHA256.
Его reference manifest покрывает только изученный cfbab96, а не недоступные новые production байты.

Нужны для сверки (без ключей/токенов/секретных значений):
- фактические manager_tasks_service.php, manager_tasks.php, manager_tasks_view.php;
- analytics_integration.php, analytics_manager_pilot_budget.php и остальные analytics_* dependencies;
- migrations/SHOW CREATE TABLE для manager_tasks, task events/slots, inbox/bindings и budget tables,
  включая поля/индексы удаления и повторного открытия;
- PHP/MariaDB версии и extensions, относительные пути storage/config, metadata владельцев/прав;
- отредактированные от секретов unit/drop-in/cron и перечень файлов runtime integrity manifest.
Сначала проверить diff production ↔ cfbab96; затем подготовить отдельный reviewed patch,
сохраняющий все изменения жизненного цикла. Просто обновить hash для прохождения проверки нельзя.

## Существующие механизмы и безопасность

analytics_sync.php задаёт disabled/dry_run/enabled, загружает защищённую локальную policy
и объединяет её с DB business policy; DB approval всегда имеет приоритет.
processAnalyticsEvent() повторно читает policy/binding/manager mappings в транзакции,
проверяет подписанные manifests и receipts, фиксирует inbox и decision audit.
createManagerTask() владеет atomic reservation и уникальными task slots; его остальная
логика жизненного цикла не переписывается. Автоматическое закрытие, переназначение и
переоткрытие задач не добавляются. HTTP API/RBAC/CSRF не ослабляются.

Новый CLI закрыт для HTTP до загрузки зависимостей.
Transport — локальные stdin/stdout, не новый публичный endpoint создания задач.
Policy action требует root execution context; обычный worker использует защищённую
конфигурацию/права DB. Никакие private key/token/SQL exception/customer payload не выводятся в ошибках.
Keyrings содержат публичные ключи; закрытые ключи и HMAC/token не попадают в Git или браузер.

## Контракт отправителя и получателя

Host runner вызывает:
- `php API/analytics_permanent_cli.php health`, stdin `{}`.
  Успех строго `{"receiver":"reachable"}`, без дополнительных полей stdout.
  Проверяется доступность необходимых таблиц и sodium; это не approval.
- `... preflight`, затем `... deliver`, один и тот же неизменяемый JSON page.
- `... policy REVIEWER` — отдельная операторская команда, не действие host runner.

Page строго содержит job_id, release_id, events (1–25).
Каждый item: положительный integer event_id, 64 lowercase hex event_hash, event_json
(до 65536 UTF-8 bytes). run.id события равен release_id.
SHA256 проверяется по точным байтам event_json: повторная PHP-сериализация не используется
из-за различий float 1.0/1 с Python. Дубли event_id/лишние поля/смешанные release отклоняются.
CLI ограничивает stdin 4 MiB; вся страница валидируется до обработки её первого элемента.

Ответ: `{"job_id":...,"release_id":...,"receipts":[...]}`.
Receipt содержит event_id, event_hash, decision, reasons, task_id (при наличии),
created_for_release. Deliver возвращает TASK_CREATED/TASK_EXISTS/BLOCKED/REVIEW —
именно эти решения принимает churn_schedule.acknowledge().
Reasons — до 30 кодов A-Z0-9_ длиной до 64.
Preflight может вернуть DRY_RUN_READY; его ответ никогда не передаётся как успешный ACK.
Повтор TASK_CREATED → TASK_EXISTS сохраняет task_id и created_for_release по сохранённому
source_signal_key и release в evidence; потерянный ACK не увеличивает число созданных задач.
Неизменяемая старая revision не подтверждается как новая.

disabled не обрабатывает страницы; dry_run допускает только preflight.
deliver в dry_run отказывает. Preflight через processAnalyticsEvent(...,dry_run) пишет
только существующие diagnostics inbox/audit, не tasks, task history, slots или reservations.
Неутверждённая/отключённая permanent policy остаётся блокером и в preflight.

Один consumer GET_LOCK на MariaDB сериализует CLI pages; существующий shared
clients_cache_refresh.lock удерживается при чтении актуального каталога.
Directory records/review groups подготавливаются одним bounded pass для страницы.
Каждое событие обрабатывается существующей транзакцией; partial commit страницы возможен.
При неожиданном сбое нет успешного ответа страницы: sender повторяет те же события,
получая те же задачи. DB/lock failures не превращаются в разрешение.

## TRUSTED_IMPORT и PRELIMINARY_MATCH

Нужна явно утверждённая local source policy:
source_trust_mode=TRUSTED_IMPORT и совпадающий import_trust_approval_id.
Ed25519 manifest: complete=false, declared_complete_through=as_of,
complete_through=as_of для совместимости. DECLARED/DECLARED_CURRENT не повышаются
до CONFIRMED/CURRENT. Независимый файл подтверждения полноты 1С не требуется.
Upstream manifest проверяется действующим analyticsVerifyEvidence():
key id, Ed25519, canonical JSON без duplicate keys, issuer/audience, issued/expiry.
source_revisions содержит согласованные sales_ftp и clientsvr; дополнительно проверяется
их соответствие source_revision и clients_revision. Свежесть закрытого московского дня,
cache revision, max signal/manifest ages и local explicit refusals остаются обязательными.

Preliminary binding остаётся REVIEW со stage PRELIMINARY_MATCH.
Проверяются signed receipt, client/group/full cards hash, manager и manager binding,
revision, last purchase/history hash, scope, cache freshness и matching_policy.
Текущая полная группа пересчитывается действующим механизмом.
Конфликт менеджеров, неоднозначность, новая покупка, revoked/changed binding или stale receipt
блокируют задачу. Никакого автоматического VERIFIED.

## Бюджеты и совместимость

Добавочная миграция создаёт три permanent таблицы, policy выключена и не утверждена.
Отдельный permanent дневной ledger и cooldown — уже предусмотренный протокол SJ.
Исчерпанный общий pilot lifetime/daily ledger не обнуляется, не изменяется и не используется
для permanent reservation. При этом прежний cap менеджера (5 исходных automatic CREATED,
только managers 3/6) сохранён. Helper получил явный флаг проверки pilot lifetime;
существующие вызовы сохраняют прежнее поведение по умолчанию.
Один locked pilot budget row сохраняет сериализацию с legacy automatic writers,
а permanent row/ledger защищают новый дневной лимит.
Cap менеджера остаётся lifetime: это намеренный дополнительный ограничитель.
Изменение этой бизнес-политики требует отдельного решения; silent unlimited permanent
delivery вместо него не допускается.

Отличия от companion package Sales Journal:
- его patch полностью обходил manager pilot cap; здесь cap сохранён;
- CLI поддерживает dry_run preflight, проверяет целую страницу/дубли/контекст;
- errors/runtime DB faults fail closed, release attribution сохраняется при retry.
Менять sender из-за этих дополнительных отказов не нужно: он уже принимает BLOCKED/REVIEW.
collect_churn_status.py показывает legacy pilot budgets; они не являются permanent ledger.
Для достоверного permanent мониторинга нужны durable SJ receipts и отдельные SQL aggregates
permanent reservations. Расширение observer, если потребуется, — отдельный SJ review.

## Тестирование и честные ограничения

Добавлены PHP тесты exact-byte page contract, permanent policy/daily cap/cooldown,
real synthetic Ed25519, trusted import, preliminary match, stale/bad signature/revisions,
binding/manager changes, replay и dry_run.
MariaDB tests проверяют реальный CLI, InnoDB failure rollback, concurrent same signal,
lost ACK attribution, существующий analytics_concurrency_test.
analytics_tasks_test/analytics_sync_test/analytics_policy_test запускаются рядом с
неизменённым cfbab96: совпадающие baseline failures печатаются отдельно,
новые/изменившиеся failures делают CI красным. Ошибки не скрываются.

CI: PHP 8.3 + SQLite/libsodium и отдельная MariaDB 10.11 service.
Только allowlisted analytics_tasks_test/analytics_race_test DSN, синтетические клиенты,
одноразовые тестовые ключи; production network/config не используются.
Installer package проверяется patch --fuzz=0 в временной копии baseline.

На момент подготовки локальная Cloud execution environment недоступна.
Добавление теста не означает его успешного запуска; результаты нужно брать из конкретного CI run PR.
Фактические FirstVDS PHP/MariaDB версии неизвестны; CI не объявляется их точной копией.
Отдельно остаются НЕПРОВЕРЕННЫМИ:
1. soft deletion/reopen/duplicate-open/access-control regressions production lifecycle;
2. настоящий PostgreSQL → host run_churn_automation → PHP/MariaDB end-to-end обмен;
3. реальные FTP revisions, receipt export HTTPS, signer/HMAC/key rotation;
4. systemd sandbox, time/memory/large-group limits, restart/maintenance races на FirstVDS.
До этих проверок интеграция не считается готовой к Production.

## Пошаговая установка — отдельная разрешённая операция

1. Не применять git checkout/reset/copy всего API к работающему каталогу.
   Получить фактические файлы выше, сравнить diff, зафиксировать их SHA256/владельцев.
   Проверить backup и восстановление MariaDB/code/private manifests; storage не удалять.
2. На изолированном клоне FirstVDS воспроизвести soft delete/reopen/RBAC.
   Согласовать минимальный patch к фактическим байтам; reference installer --check
   обязан отказать при любом несовпадении с reviewed baseline.
3. Отдельно проверить/применить database/20261121_analytics_permanent.sql.
   Миграция не запускается installer/runtime автоматически, повторно вслепую не применять.
   Не изменять pilot budgets, существующие tasks и audit.
4. По утверждённой процедуре остановить конкурирующие old/new consumer timers/cron,
   дождаться завершения oneshot. Убедиться, что фактические receiver/producer policies
   выключены; env flag сам по себе не доказывает отключение других consumers.
5. Проверить package commit и manifest; `bash scripts/install_churn_permanent.sh --check`
   только читает код, lint и patch dry-run. Не заменять source hash для обхода отказа.
   `--apply` — только явное root действие после сверки; оно сохраняет private backup,
   меняет лишь reviewed hunk/два новых файла, при ошибке восстанавливает оригиналы.
   DB/keys/storage/policies/units/runtime trust manifest оно не меняет.
6. PHP code root-owned, web user без права записи. Key/config directories вне web root,
   root:root 0700; private files 0600. Binding signer file — base64 libsodium secret64,
   Calltrack public keyrings — raw public32 base64 с key_id. Host upstream signer использует
   PEM Ed25519; HMAC release key нужен только host/SJ. Для export — отдельный read-only token.
   Проверить действующий api/config.php: host ENV_KEYS runner allowlist не передаёт signer
   private key/export token получателю. Не печатать env и не передавать секреты в argv.
7. Independently review runtime-code-sha256.json: реальные after hashes всех REQUIRED_FILES,
   host scripts и service/timer, включая churn_trusted_ftp.py. Не «перехешировать всё»
   автоматически как способ утвердить новый код. Сохранить прежний manifest.
8. Host.json указывает на фактический calltrack-v3-20261026, не Git checkout.
   Проверить runner/adapter/collector из SJ без их изменения здесь.
   systemd-analyze verify, private runtime dirs, точный storage ReadWritePaths,
   locks/CA/TLS/DB grants и старые consumers проверить в изолированном окружении.
   Установка файлов unit не означает enable/start.
9. Согласовать issuer, required_sources, import_trust_approval_id, scope/hash, matching policy,
   generator /2, confidence/priority/due-days, max ages и реальные client/manager bindings.
   Local DB task policy и обе permanent policies утверждаются отдельно.
   Draft отключает; approve не включает. Сверить возвращённые revisions, не угадывать их.
10. Выполнить isolated real end-to-end tests и все обязательные проверки ниже.
    Первый production цикл и enable timer — только отдельное разрешение оператора;
    в этой работе автоматическая отправка не включается.

Операторский CLI policy синтаксис: `php API/analytics_permanent_cli.php policy REVIEWER`,
JSON из приватного stdin: action + revision; draft дополнительно daily_limit,
cooldown_days, producer_revision, generator_version; approve требует
confirm_business_approval=true. Реальные значения здесь не предлагаются и не меняются.

## Откат и обязательный go/no-go

Сначала штатно отключить producer/receiver policies и timer, дождаться consumers.
Не считать остановку процесса доказательством rollback уже committed страницы.
Сохранить receipts/inbox/reservations/journal; сопоставить release и task IDs без вывода payload.
Вернуть только оригинальные production файлы из проверенного backup, не старую Git-версию.
Вернуть соответствующий сохранённый integrity manifest, проверить hashes/health.
Новые tables/history/storage не удалять и не уменьшать использованные бюджеты.
Исчерпанный pilot автоматически обратно не включать. Soft-deleted/done tasks не менять.

Go/no-go перед реальными задачами:
- согласована база PR и production diff, lifecycle/RBAC/CSRF regressions зелёные;
- schema/indexes и PHP/MariaDB extensions совпадают с изолированным проверенным стендом;
- PHP/SQLite/MariaDB/CLI/race tests и настоящий sender end-to-end прошли;
- trust DECLARED остаётся DECLARED, текущие FTP/cache/binding revisions и подписи проверены;
- invalid/stale/ambiguous/conflicting/revoked inputs не создают задач;
- retry/concurrent delivery не создают дублей, caps/cooldown/rollback подтверждены;
- private keys/token permissions, runtime integrity, sandbox/storage и единственный consumer проверены;
- approvals/revisions/минимальный бизнес-лимит согласованы и есть отдельное разрешение запуска.

## Результаты CI и дополнительные проверки locking

Проверенный run до дополнительного усиления locking:
https://github.com/kasherehauwa01-sudo/Calltrack/actions/runs/44898831087
(PHP 8.3.37, MariaDB 10.11.19). Обе jobs прошли: signatures/trusted/preliminary,
реальный CLI, failure rollback, concurrent same signal, lost ACK, package hashes/patch.
Existing analytics_policy_test проходит.
Два baseline failure воспроизведены на неизменённом cfbab96:
analytics_tasks_test — unsigned synthetic evidence отклоняется существующим signed gate;
analytics_sync_test — отсутствует prerequisite PostgreSQL export fixture.
Они не называются успешными функциональными тестами.

Дополнительно permanent ledger и cooldown используют MariaDB locking reads, чтобы
ранее созданный consistent-read snapshot не скрыл чужую committed reservation.
Отдельный race test намеренно создаёт старые snapshots у двух разных сигналов,
конкурирующих за последнее место в дневном бюджете. Результат нового run нужно
проверять для актуального SHA PR. Это не снимает перечисленных production блокировок.
