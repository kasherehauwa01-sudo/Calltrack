# Серверная сверка перед любым внедрением

Дата подготовки: 21.10.2026, Europe/Moscow. Эти команды **не выполнялись на production**. Merge, deploy, миграции, cron и включение автоматизации не разрешены. Политика confidence/priority/deadline не утверждена. Сначала получить вывод сверки; при обнаружении server-only модуля задач или расхождений остановить объединение.

## 1. Код и наличие серверного модуля

Выполнять из обычного shell сервера без копирования приглашения `root@...#`. Команды читают Git; optional index locks отключены. Не выполнять checkout/pull/fetch/reset/clean, update.sh или установку зависимостей на сервере.

```bash
git --no-optional-locks -C /var/www/html/vr/calltrack status --short --branch
git --no-optional-locks -C /var/www/html/vr/calltrack rev-parse HEAD
git --no-optional-locks -C /var/www/html/vr/calltrack diff --name-status
git --no-optional-locks -C /var/www/html/vr/calltrack diff --cached --name-status
git --no-optional-locks -C /var/www/html/vr/calltrack ls-files --others --exclude-standard
git --no-optional-locks -C /var/www/html/vr/calltrack for-each-ref --format='%(refname:short) %(objectname)' refs/heads refs/remotes/origin

git --no-optional-locks -C /var/www/html/vr/sales status --short --branch
git --no-optional-locks -C /var/www/html/vr/sales rev-parse HEAD
git --no-optional-locks -C /var/www/html/vr/sales diff --name-status
git --no-optional-locks -C /var/www/html/vr/sales ls-files --others --exclude-standard

git --no-optional-locks -C /var/www/html/vr/clients status --short --branch
git --no-optional-locks -C /var/www/html/vr/clients rev-parse HEAD
git --no-optional-locks -C /var/www/html/vr/clients diff --name-status
```

Проверить существующие task/analytics endpoints и схемы по именам файлов, без печати клиентских данных/секретов:

```bash
cd /var/www/html/vr/calltrack
rg --files api database analizmop | rg -i 'task|binding|analytics|signal'
rg -l -i --glob '*.php' --glob '*.sql' 'manager_tasks|analytics_inbox|CREATE TABLE[^;]*task' api database
rg -l --glob '*.php' 'function managerNameKey|function clientCatalogRecords|function clientManagerField' api
```

Если rg отсутствует, разрешён read-only поиск файлов обычным find; не устанавливать пакеты в рамках сверки. Вывод Git содержит только имена/SHAs; private/untracked конфиги не открывать и не загружать. При неизвестных файлах запросить владельца, не удалять/заменять их.

Опционально проверить remote refs без изменения checkout:

```bash
git -C /var/www/html/vr/calltrack ls-remote --heads origin main feat/analytics-manager-bindings
git -C /var/www/html/vr/sales ls-remote --heads origin main feat/analytics-inactivity-shadow feat/analytics-calltrack-integration
```

Разработанные изменения сравнивать **локально/на staging** с серверным HEAD и списком локальных изменений, не запускать merge на сервере. Ветка Sales Journal наследует принятую feat/analytics-inactivity-shadow, которая пока не в main. Review нового delivery diff относительно этой ветки, не потерять её миграции21/22.

## 2. Runtime metadata

```bash
php -v
php -m
php --ini
python3 --version
docker --version
docker compose version
```

Нужны PHP8.1+ (array_is_list), PDO MySQL, mbstring, curl; для SQLite-кэша/локальных replay — pdo_sqlite. Проверить поддерживаемость CHECK/window functions выбранной MySQL/MariaDB/PostgreSQL версии. PHP CLI и web/FPM могут различаться: сопоставить только версии/модули через уже имеющуюся административную диагностику. Не публиковать phpinfo.

Не выполнять `env`, `printenv`, `docker inspect`, `docker compose config`, `cat .env`, `cat config.local.php`, `systemctl show ... Environment` или `nginx -T`: они могут раскрыть секреты. Не запускать PHP config/getPdo/автосоздающие tables endpoints как «read-only проверку». Даже GET может обновлять сессию/кэш.

Безопасно найти только имена файлов, где настройки упоминаются:

```bash
cd /var/www/html/vr/calltrack
rg -l --hidden --glob '!storage/**' --glob '!.git/**' 'CALLTRACK_ANALYTICS_MODE|CALLTRACK_MANAGER_BINDINGS_ENABLED|CALLTRACK_ANALYTICS_POLICY_FILE' api scripts
cd /var/www/html/vr/sales
rg -l --hidden --glob '!.git/**' --glob '!data/**' --glob '!storage/**' 'ANALYTICS_SIGNALS_ENABLED|analytics_signals_enabled' backend docker-compose.yml
```

Владелец runtime-конфигурации отдельно подтверждает **только не секретные значения**: consumer disabled, worker opt-in выключен, policy unapproved, оба типа off, обязательные бизнес-параметры не заданы. Не менять значения ради проверки. Проверить отсутствие ранее установленных analytics cron/systemd jobs по именам сервисов; полные unit/cron строки не пересылать, если в них могут быть токены. При наличии enabled/утверждённой политики — зафиксировать и запросить решение, не включать/выключать production самостоятельно.

## 3. БД — только metadata, отдельным read-only пользователем

Подключение выбирает владелец сервера через существующий защищённый профиль/socket; пароли/DSN не вводить в публикуемую командную строку и не присылать. Сначала подтвердить имя БД локально, не перепутать sales/clients/calltrack. Не запускать миграции даже «для проверки».

В MySQL/MariaDB session выполнить:

```sql
SET SESSION MAX_STATEMENT_TIME=5;
START TRANSACTION READ ONLY;
SELECT VERSION();
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND (TABLE_NAME IN ('web_users','client_manager_bindings','manager_tasks',
       'analytics_client_bindings','analytics_inbox','analytics_open_task_slots',
       'manager_task_events','analytics_processing_events','analytics_sync_state',
       'analytics_task_policy','analytics_task_type_policy','analytics_delivery_rejections')
       OR TABLE_NAME LIKE '%task%')
ORDER BY TABLE_NAME;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('web_users','manager_tasks','client_manager_bindings',
                    'analytics_inbox','analytics_task_policy','analytics_task_type_policy')
ORDER BY TABLE_NAME,ORDINAL_POSITION;
ROLLBACK;
```

`MAX_STATEMENT_TIME` — MariaDB-specific. На MySQL использовать `SET SESSION MAX_EXECUTION_TIME=5000` вместо первой строки. Ошибка неизвестной переменной не повод менять глобальные настройки. Только после обнаружения **уже существующих** policy tables отдельно прочитать их ограниченные singleton/type rows в read-only transaction:

```sql
START TRANSACTION READ ONLY;
SELECT id,revision,min_confidence,approved FROM analytics_task_policy WHERE id=1;
SELECT signal_type,enabled,priority,due_days
FROM analytics_task_type_policy ORDER BY signal_type LIMIT 2;
ROLLBACK;
```

В PostgreSQL sales session:

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout='5s';
SET LOCAL lock_timeout='1s';
SELECT version();
SELECT table_name,column_name,data_type,is_nullable
FROM information_schema.columns
WHERE table_schema='public'
  AND table_name IN ('sales','import_batches','analytics_signal_runs',
      'analytics_signals','analytics_signal_changes','analytics_delivery_head',
      'analytics_delivery_publications')
ORDER BY table_name,ordinal_position;
SELECT indexname FROM pg_indexes
WHERE schemaname='public'
  AND tablename IN ('analytics_signal_changes','analytics_delivery_publications')
ORDER BY indexname;
ROLLBACK;
```

Если schema не public — владелец указывает фактическую schema. После metadata-проверки и только если таблица есть:

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout='5s';
SELECT version_num FROM alembic_version;
SELECT id,enabled,active_run_id,published_run_id FROM analytics_signal_state WHERE id=1;
ROLLBACK;
```

Не выбирать клиентские имена/телефоны, payload_json, pin_hash, tokens. Не делать COUNT/aggregate по production sales, полнотабличные каталоги, EXPLAIN ANALYZE, VACUUM, ANALYZE или SELECT FOR UPDATE. SQLite production-cache в этой сверке не открывать через app helpers: они могут выполнять DDL/PRAGMA и создавать WAL. При необходимости metadata-копии каталога согласовать отдельно.

## 4. Критерий остановки и результат сверки

Передать только SHAs, статус dirty/untracked, имена task-related файлов/таблиц, metadata версий и подтверждение off/unapproved. При server-only tasks/изменённой схеме/другом auth contract остановить объединение. Подготовить diff совместимости отдельно, затем запросить разрешение на следующий этап. До этого никакие migrations/deploy/mode switches не выполнять.

Проверку399 диагностических кандидатов выполнять вне production по docs/aviator_399_dry_run.md. Эта read-only сверка не запускает producer/consumer даже в dry_run: такой запуск может писать inbox/checkpoints и потому не является read-only production-операцией.
