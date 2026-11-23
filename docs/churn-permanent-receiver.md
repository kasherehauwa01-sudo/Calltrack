# Постоянный churn receiver: FirstVDS production lifecycle

Draft PR #518, ветка feat/churn-permanent-receiver. Ни merge, ни установка,
ни автоматическая отправка не выполняются этой доработкой.

## Проверенная база и границы доказательств

Git baseline: cfbab96539bbcadcf1963991f77c10ccc78ce5a8.
Production API: /var/www/html/vr/calltrack-v3-20261026/api/.
Git checkout: /var/www/html/vr/calltrack/ — это НЕ рабочий API.

Владелец предоставил полный unified diff трёх production-файлов относительно cfbab96
и подтвердил версии PHP 8.5.12 / MariaDB 11.8.6, столбцы и индексы lifecycle.
Diff зафиксирован как deploy/churn-permanent/production-lifecycle.patch.
Он применяется только к временной Git-копии в тестах для воспроизведения baseline;
установщик НЕ применяет lifecycle patch к Production.

Production изменяет manager_tasks_service.php, manager_tasks.php, manager_tasks_view.php.
analytics_integration.php, analytics_manager_pilot_budget.php, analytics_source_trust.php,
analytics_verified_approval.php подтверждены владельцем как совпадающие с cfbab96.

Ожидаемые SHA256 после применения предоставленного diff (LF/UTF-8):
- manager_tasks_service.php: 79adb9cf875086f795e3acdbd46b29e71aad9ec625843902d8384e5ceac6d4f6
- manager_tasks.php: f368ca7cadfa68615a3a93ce04103607cff5d1b48661b8e09e0d301f9219d1de
- manager_tasks_view.php: aa0e698182b7144b02a4df91ae15478bc828a31ea66bc6c331c4e9fe7de6ddfe

Это ожидаемые hashes восстановленной из diff базы, а не результат удалённого
чтения файлов FirstVDS. Перед установкой оператор сверяет реальные bytes через --check.
Иное окончание строк, дополнительная правка или другая версия любого из семи файлов
дают отказ. Не «обновлять hashes», чтобы обойти отказ: получить фактический diff и review.

Название reviewed-git-baseline.json сохранено для преемственности пакета, но
baseline_kind теперь строго firstvds-production-lifecycle-user-diff.
Чистый cfbab96 больше НЕ является поддерживаемым installation baseline.

Исходная production/max-message-sync на GitHub — старый 715be84 без task subsystem.
PR остаётся к main/cfbab96; перенос подсистемы в ту старую ветку не выполняется.

## Что сохраняется из Production

В Git перенесены ровно предоставленные lifecycle-изменения:
- taskCanRead запрещает чтение deleted_at задач всем ролям;
- DELETE endpoint использует прежний requireWebUser/CSRF и deleteManagerTask;
- soft delete: version check/increment, deleted_at, DELETED audit, освобождение slot,
  строка задачи не удаляется;
- manager list/count/types/filters/pagination исключают deleted_at до выборки;
- ручное повторное открытие DONE/CANCELLED, optimistic locking и текущий role policy;
- findOpenTaskInSlot ищет slot_key И reopened_slot_key;
- reopen alias hash('sha256','reopened-task:'.$id), без уникального ограничения
  на reopened_slot_key. Первичный slot и несколько reopen aliases могут сосуществовать.
- автоматический receiver не закрывает, не переназначает, не переоткрывает
  и не восстанавливает удалённые задачи.

Не добавлялись новые правила доступа или запрет существующего reopen.
Supervisor может удалять по предоставленному diff, но не редактировать задачу;
manager не может удалить, отменить новую задачу или переназначить её.
Удалённый эпизод сохраняется в истории дедупликации: повтор его сигнала не
восстанавливает задачу и не создаёт клон.

## Минимальный установочный patch и контроль целостности

deploy/churn-permanent/integration.patch построен относительно production baseline.
Он меняет ровно пять файлов:
analytics_integration.php, manager_tasks_service.php, analytics_manager_pilot_budget.php,
analytics_verified_approval.php, analytics_source_trust.php.

В manager_tasks_service.php единственный изменяемый участок — резервирование
automatic budget. Soft delete/reopen/findOpenTaskInSlot/task ACL не переписываются.
manager_tasks.php и manager_tasks_view.php являются read-only guards:
их hashes проверяются до и после, patch их НЕ меняет.

Семь pre-install hashes, девять post-install hashes (семь guards + два receiver-файла),
hash lifecycle evidence и hash integration patch заданы в reviewed-git-baseline.json.
Default --check читает код, проверяет hashes, PHP lint и patch --dry-run --fuzz=0;
не подключается к DB и ничего не устанавливает.
--apply повторяет проверки под install flock, проверяет schema/версии read-only,
сохраняет пять оригинальных файлов и manifests в private backup и применяет patch.
Неизвестная база, существующие новые target-файлы или несовпадающий hash дают отказ.
Schema/policy/keys/runtime trust manifest/services не меняются автоматически.

Package test запускает настоящий --check в временной копии скрипта с подставленными
только filesystem root/package; --apply и пути VPS в тесте никогда не используются.
Проверены positive production baseline, отказ чистому cfbab96 и one-byte drift
каждого из семи guards, неизменность endpoint/view и отсутствие записей при --check.

## Схема: дополнительные lifecycle migrations НЕ нужны

Уже подтверждены:
- manager_tasks.deleted_at: timestamp;
- analytics_open_task_slots.reopened_slot_key: char(64);
- slots PRIMARY(slot_key) unique, task_id(task_id) unique,
  ix_analytics_reopened_slot(reopened_slot_key) NON-UNIQUE;
- tasks PRIMARY(id) unique, uq_task_signal(source_signal_key) unique,
  ix_task_owner_status(assignee_id,status,due_at) NON-UNIQUE.

Никаких production ALTER этих таблиц в пакете нет.
ALTER lifecycle columns присутствуют только в allowlisted disposable test fixture.
Существующая database/20261121_analytics_permanent.sql создаёт лишь три НОВЫЕ
permanent таблицы с выключенной и неутверждённой policy.
Применять её нужно отдельно только после проверки, что таблицы ещё не установлены;
повторный запуск migration вслепую не разрешён.

scripts/check_churn_production_schema.php — отдельная CLI read-only проверка.
Она читает config именно работающего API, использует SELECT information_schema/SELECT VERSION(),
проверяет InnoDB, nullable deleted_at timestamp, char(64) reopen key и точные наборы
подтверждённых индексов, включая НЕуникальный reopen index.
CLI отказывает при версиях вне явно заявленных PHP 8.5.12 / MariaDB 11.8.6.
Никакого auto-repair/DDL, изменения данных или вывода credentials нет.
Дополнительный индекс/другой тип/версия — причина review, а не автоматической миграции.

## Контракт, источник доверия и безопасность

Сохраняется существующий контракт Sales Journal:
run_churn_automation.py вызывает локальные health, preflight, deliver.
health stdin {} → строго {"receiver":"reachable"}.
Page: job_id, release_id, events (1–25), item: integer event_id, event_hash,
event_json до 65536 bytes. Hash — по точным Python JSON bytes, без PHP пересериализации.
CLI stdin ограничен 4 MiB. Целая страница проверяется до первых diagnostic writes.
Нет нового HTTP endpoint создания задач; policy CLI требует root operator context.

Receipt: event_id, event_hash, decision, reasons, task_id при наличии,
created_for_release. Deliver: TASK_CREATED/TASK_EXISTS/BLOCKED/REVIEW.
Повтор TASK_CREATED → TASK_EXISTS сохраняет attribution из сохранённого task evidence.
Неожиданная ошибка не подтверждает страницу; committed элементы безопасно повторяются.

disabled не обрабатывает страницы. dry_run допускает только preflight;
deliver в dry_run отказывает. Preflight пишет прежние inbox/audit diagnostics,
но не manager tasks, task history, slots или reservations.
processAnalyticsEvent() остаётся владельцем gates/inbox/task transaction.
Каждая доставка заново проверяет policy/binding/manager state в транзакции.
Имеются consumer GET_LOCK и существующий clients_cache_refresh shared lock.

TRUSTED_IMPORT:
source_trust_mode и import_trust_approval_id должны совпадать с local approval;
complete=false, declared_complete_through=as_of и compatibility complete_through=as_of.
DECLARED/DECLARED_CURRENT не повышаются до CONFIRMED/CURRENT.
Upstream manifest и Calltrack receipt проходят действующие Ed25519/keyring/canonical JSON/
issuer/audience/issued_at/valid_until gates.
source_revisions sales_ftp/clientsvr должны соответствовать source_revision/clients_revision.
Свежесть закрытого московского дня/cache revision/local explicit refusals сохраняется.

PRELIMINARY_MATCH остаётся REVIEW, не VERIFIED. Текущая полная группа карточек,
client_id, manager/binding revision, history/last purchase, scope/cache/group hashes,
signed matching_policy и receipt заново сверяются.
Ambiguity/manager conflict/stale/revoked/changed binding блокируют задачу.

Пилотная и постоянная политики разделены явно:
- assertAnalyticsAutomaticManager сохраняет допустимый круг managers 3/6 и требование транзакции.
- assertAnalyticsManagerPilotBudget вызывается только для pilot; прежний учёт original
  automatic CREATED и персональный cap 5 не меняются, общий pilot budget остаётся 10.
  История по-прежнему консервативно учитывает все прежние automatic CREATED;
  переименование режима, удаление, смена исполнителя или reopen не обнуляют её.
- Permanent не читает lifetime pilot budget/history и не вызывает пилотный cap.
  Для новых задач обязателен отдельный approved+enabled permanent policy с точными
  revision/producer_revision/generator/daily_limit/cooldown_days.
  Дневной лимит общий для всех допустимых менеджеров; отдельный персональный
  permanent лимит не придумывается. Допустимый диапазон daily_limit 1–100,
  cooldown 1–3650 дней — это границы валидации, а не утверждение значения.
  Конкретные значения требуют отдельного бизнес-согласования.
- Отсутствующая, неутверждённая, отключённая или несовпадающая permanent policy
  блокирует обработку. Нет fallback на pilot или неограниченную доставку.
Pilot counters/history не сбрасываются, не возвращаются и не пересчитываются.
Дневной ledger/cooldown используют current locking reads под InnoDB REPEATABLE READ,
чтобы старый snapshot не скрыл committed чужую reservation.

Sales Journal, sender scripts, service/timer не изменяются.
collect_churn_status.py показывает legacy pilot budget: permanent counts нужно
проверять по durable SJ receipts и permanent ledger, не выводить из pilot exhaustion.

## Автоматические проверки и совместимость

CI сохраняет PHP 8.3/MariaDB 10.11 regressions и добавляет точные FirstVDS версии:
PHP 8.5.12 и MariaDB image 11.8.6. Утверждение совместимости делается только после
проверки реальных PHP_VERSION/SELECT VERSION() в job; тихой подмены версий нет.

tests/churn_production_lifecycle_test.php покрывает:
soft delete без физического удаления, ACL всех ролей, audit/slots/version conflicts,
deleted list/count/type/completed filtering, DONE/CANCELLED reopen,
reopen alias и занятый primary slot, отсутствие третьей задачи,
подписанный replay после reopen и удаления, конкурентную доставку после reopen
(реальная multi-process проверка на MariaDB), отсутствие изменения текущих задач.

Budget fixture воспроизводит 5 исторических задач менеджера 3, 5 менеджера 6 и 10/10
общего pilot budget. Проверяются создание permanent для обоих менеджеров, отказ pilot,
missing/unapproved/disabled/mismatched/out-of-bounds policy, дневной предел,
неизменность всех исторических строк/событий/пилотных счётчиков.
Signed/CLI/concurrency/lifecycle тесты используют ту же исчерпанную историю.

Сохраняются signed/TRUSTED_IMPORT/PRELIMINARY_MATCH/budget/CLI/race тесты.
Существующий analytics_concurrency_test используется с production lifecycle fixture.
analytics_tasks_test и analytics_sync_test сравниваются с неизменённым cfbab96:
unsigned synthetic evidence / prerequisite PostgreSQL export fixture остаются
отдельно видимыми baseline failures. Зелёная comparison job не означает успешного
полного existing suite.

Текущие выполненные результаты и SHA публикуются в PR #518/Actions.
До окончания новых version-specific jobs тесты не объявляются успешными.
Cloud workspace недоступен; execution выполняется в одноразовых GitHub Actions.
Production DB/files/keys/storage/services не используются тестами.

## Точный безопасный план установки — здесь НЕ выполняется

1. Зафиксировать actual hashes/versions и status policies/timers/cron; снять проверенный
   backup MariaDB, private runtime manifest, пяти изменяемых code-файлов и конфигурации.
   Проверить восстановление. Не делать git checkout/copy всего API на working root.
2. Получить reviewed commit PR в Git checkout. Сверить пакет с reviewed commit,
   выполнить:
   bash /var/www/html/vr/calltrack/scripts/install_churn_permanent.sh --check
   php /var/www/html/vr/calltrack/scripts/check_churn_production_schema.php
   Обе команды — проверки; вторая читает только действующую schema/версии.
   При любом отказе остановиться, получить diff/SHOW CREATE и review; hashes не подменять.
3. Проверить новые permanent tables. Если отсутствуют — DBA отдельно рассматривает и
   применяет только 20261121_analytics_permanent.sql. Если уже существуют — сравнить
   их schema, не выполнять CREATE/INSERT заново и не сбрасывать policy.
   Lifecycle columns/indexes не мигрируются.
4. По утверждённой maintenance процедуре отключить receiver/producer policies и все
   old/new consumer timers/cron, дождаться завершения oneshot. Сам env flag не доказывает,
   что другой consumer остановлен. Не kill процесс посреди задачи без расследования commit.
5. Только после отдельного разрешения root оператор выполняет --apply с
   CALLTRACK_ANALYTICS_MODE=disabled. Скрипт дополнительно проверяет known units inactive,
   берёт install lock, повторяет hashes/schema/version checks, сохраняет backup,
   применяет минимальный patch, добавляет два receiver-файла, проверяет lint и after hashes.
   manager_tasks.php/view.php остаются byte-identical, storage/DB/policies не меняются.
6. Независимо review новый runtime-code-sha256.json для фактических after bytes и ВСЕХ
   реально исполняемых зависимостей (включая analytics_manager_pilot_budget.php).
   Не автоматически «перехешировать всё» как способ утверждения доверия.
   Сохранить прежний manifest; указывать working root calltrack-v3-20261026, не Git root.
7. Проверить private keys/config вне web root: root-owned dirs 0700, файлы 0600.
   Upstream signer PEM Ed25519, binding signer base64 libsodium secret64, public keyrings
   raw public32 base64 с key_id; HMAC release key нужен только host/SJ.
   Export token read-only. Никаких secrets в Git/HTML/argv/logs.
8. На изолированном стенде проверить настоящий PostgreSQL → host runner → receiver,
   FTP/cache/export HTTPS/signatures/key rotation, service sandbox/точный storage
   ReadWritePaths/единственный consumer; native task policy, обе permanent approvals,
   TRUSTED_IMPORT approval id и фактические revisions. Draft/approve не включают доставку.
9. Пройти go/no-go ниже. Enable policy/timer и первый минимальный production цикл —
   отдельное разрешение оператора, НЕ часть PR/installer.

## Откат

1. Отключить producer/receiver policies и consumer timer/cron, дождаться завершения работы.
   Сохранить release/inbox/receipt/ledger evidence; stop не доказывает rollback committed page.
2. Вернуть только пять файлов из private install backup: это именно production lifecycle
   originals, не cfbab96 из Git. manager_tasks.php/view.php установщик не менял;
   сверить их guard hashes, не заменять при откате.
3. Восстановить только соответствующий реально восстановленным bytes старый integrity
   manifest. Новые receiver-файлы сохранить для расследования при выключенной доставке,
   не запускать их со старым manifest. Проверить code hashes, PHP lint и штатный web API.
4. Новые таблицы/reservations/inbox/audit не удалять; consumed budgets не уменьшать.
   storage, soft-deleted/DONE/reopened tasks не менять. Исчерпанный pilot автоматически
   обратно не включать.

## Оставшиеся production go/no-go

- Реальные семь hashes совпали с предоставленным diff и --check.
- Nullable/schema/index/engine checks прошли без DDL; существующие lifecycle возможности
  подтверждены на declared PHP/MariaDB CI, а на VPS выполнен разрешённый smoke check.
- Настоящий PostgreSQL → run_churn_automation.py → PHP/MariaDB end-to-end,
  реальные FTP/cache revisions, export/signers/key permissions проверены отдельно.
- Runtime manifest, systemd sandbox/storage, concurrent consumers, bounded resource
  usage и business approvals/revisions/minimal limits проверены.
- Есть явное отдельное разрешение первого production цикла.
CI с синтетическими подписанными fixtures не заменяет эти проверки и не делает
интеграцию автоматически production-ready.

## Особенность MariaDB 11.8, обнаруженная выполненным CI

На PHP 8.5.12 / MariaDB 11.8.6 прежний analytics_concurrency_test получил
SQLSTATE HY000 / native errno 1020 (Record has changed) при гонке unique slot.
Его bounded worker retry дополнен ровно этим transient-кодом; проверки exactly-one
task/slot не изменены. Production service не получает скрытого retry или ослабления gates.
Receiver при таком PDO exception откатывает task/budget/inbox, не подтверждает страницу
и освобождает consumer lock; host использует существующую повторную доставку.
Отдельная SQL fault injection 1020 проверяет rollback перед последующим успешным retry.

При гонке ledger locking read MariaDB 11.8 также может вернуть 1020 вместо выдачи
нового committed record. Это fail-closed отказ, не разрешение сверх лимита.
Worker-тест повторяет ВСЮ транзакцию с новым snapshot после rollback;
retry внутри прежней snapshot не используется. Exactly-one-remaining-slot assertion сохранён.
