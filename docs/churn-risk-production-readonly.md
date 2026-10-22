# CHURN_RISK: безопасная production-сверка (не выполнялась)

Все команды ниже — чтение состояния. Не запускать app/bootstrap jobs, ensure* helpers, sync/worker/release, migration, Docker build/up/restart или refresh кэша. Не загружать полный `/integration/clients`. Не печатать .env, Docker Env, private config, токены, телефоны, клиентские имена или payload_json. При ошибке/отсутствии прав остановиться; не создавать роль, БД, schema, cache directory или таблицу для проверки.

Данные/команды с конкретным client_id хранить в private run sheet. В публичный отчёт передавать только структурные результаты/счётчики/статусы. Эти проверки способны выявить отказ/неполноту, но **не доказать** полноту 1С/FTP при отсутствии независимого manifest.

## 1. Server Git и образ — из Bash на сервере

```sh
git --no-optional-locks -C /var/www/html/vr/sales rev-parse HEAD
git --no-optional-locks -C /var/www/html/vr/sales status --short --branch
git --no-optional-locks -C /var/www/html/vr/sales diff --name-status
git --no-optional-locks -C /var/www/html/vr/sales diff --cached --name-status
git --no-optional-locks -C /var/www/html/vr/calltrack rev-parse HEAD
git --no-optional-locks -C /var/www/html/vr/calltrack status --short --branch
git --no-optional-locks -C /var/www/html/vr/calltrack diff --name-status
git --no-optional-locks -C /var/www/html/vr/calltrack diff --cached --name-status
docker inspect --type container --format 'image_id={{.Image}} image_ref={{.Config.Image}} status={{.State.Status}} started={{.State.StartedAt}}' sales-journal-backend
```

Никакого fetch/pull/checkout на production для сверки. Не удалять untracked backup и вложенный sales-journal в Calltrack. Не выводить remote URL, если в нём могут оказаться credentials. Новые GitHub SHA/PR не подтверждают, что серверный образ содержит те же файлы.

Whitelist runtime-настроек, без дампа Env/секретов:

```sh
docker exec -i sales-journal-backend python -B - <<'PY'
import os
for key in ('ANALYTICS_SIGNALS_ENABLED','ANALYTICS_AUTOMATION_ENABLED'):
    value=os.environ.get(key)
    if value is None: result='UNSET: verify source default'
    elif value.strip().lower() in {'0','false','off','no'}: result='FALSE'
    elif value.strip().lower() in {'1','true','on','yes'}: result='TRUE: STOP'
    else: result='INVALID: STOP'
    print(key,result)
mode=os.environ.get('ANALYTICS_SIGNALS_MODE')
print('ANALYTICS_SIGNALS_MODE',mode if mode=='SHADOW' else 'UNSET_OR_UNEXPECTED')
print('manifest_key_configured',bool(os.environ.get('ANALYTICS_AUTOMATION_MANIFEST_KEY')))
PY
```

При неожиданном mode не копировать значение из другого источника, не «исправлять флаг» в рамках этой сверки. В candidate defaults false и SHADOW; отсутствие env необходимо сопоставить с actual source/image, а не считать доказанным false. Calltrack cron/service environment выясняется владельцем по approved scheduler config приватно; CLI environment не доказывает окружение cron/PHP-FPM. Не публиковать crontab/systemd Environment: там могут быть секреты. Итоговые безопасные сведения: наличие/частота scheduler, consumer mode/feed, policy-file configured boolean, worker enabled boolean.

## 2. Sales PostgreSQL metadata и bounded imports

**Вставлять весь блок в server Bash, не в psql.** Он сам открывает psql. При ON_ERROR_STOP соединение закрывается и транзакция откатывается. Никаких production credentials в командной строке:

```sh
docker exec -i sales-journal-db sh -c 'exec psql -X -P pager=off -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' <<'SQL'
BEGIN READ ONLY;
SET LOCAL statement_timeout = '5s';
SET LOCAL lock_timeout = '1s';
SHOW transaction_read_only;
SELECT version_num FROM alembic_version;
SELECT table_name FROM information_schema.tables
 WHERE table_schema='public' AND (table_name LIKE 'analytics!_%' ESCAPE '!' OR table_name IN ('sales','import_batches','seasonality_state'))
 ORDER BY table_name;
SELECT column_name,data_type,is_nullable FROM information_schema.columns
 WHERE table_schema='public' AND table_name='sales'
 AND column_name IN ('client_id','external_client_id','client_namespace','namespace','client','phone')
 ORDER BY column_name;
SELECT id,source_revision FROM seasonality_state WHERE id=1;
SELECT EXISTS(SELECT 1 FROM import_batches WHERE status IN ('queued','processing') LIMIT 1) AS imports_pending;
SELECT id,status,started_at,finished_at,period_start,period_end,declared_period_start,declared_period_end,source_period_method,
 total_rows,processed_rows,added_rows,duplicate_rows,skipped_rows,error_rows
 FROM import_batches ORDER BY id DESC LIMIT 20;
SELECT c.reltuples::bigint AS estimated_rows,pg_size_pretty(pg_total_relation_size(c.oid)) AS total_size
 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname='sales';
SELECT id,enabled,active_run_id,published_run_id FROM analytics_signal_state WHERE id=1;
SELECT r.id,r.status,r.phase,r.as_of,r.source_revision,r.finished_at,
 r.metadata_json->'source_decision'->>'coverage_status' AS coverage_status,
 r.metadata_json->'source_decision'->>'business_freshness' AS business_freshness,
 r.metadata_json->'source_decision'->>'accepted_data_through' AS accepted_data_through
 FROM analytics_signal_state s JOIN analytics_signal_runs r ON r.id=s.published_run_id WHERE s.id=1;
SELECT id,sequence FROM analytics_delivery_head WHERE id=1;
ROLLBACK;
SQL
```

Это metadata/последние20 imports/указатель последней публикации, не полный аудит истории. Нет MAX(sale_date), scan sales, EXPLAIN ANALYZE или полного GROUP BY phone. Оценка reltuples приблизительна. Если revision/столбцы отличаются от предполагаемых, остановить последующие блоки и сверить metadata, не создавать недостающие поля.

Вывод sales без client_id означает отсутствие прямой identity в текущей таблице, а не отсутствие ID во внешнем источнике. Истинный upstream ID/namespace, source revisions, перечень обязательных выгрузок, count/digest и закрытые периоды нужно получить отдельно из 1С/источника. Completed/20 записей/no pending сами по себе полноту не доказывают. Empty state/published_run_id=NULL — отсутствие результата, не успешная аналитика.

После **отдельно разрешённой миграции24**, только если таблицы существуют, прочитать:

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout='5s';
SET LOCAL lock_timeout='1s';
SELECT id,enabled,approved,revision,sequence,
 settings_json->>'min_confidence' AS min_confidence,
 settings_json->>'max_manifest_age_seconds' AS max_manifest_age_seconds,
 (settings_json::jsonb ? 'issuer') AS issuer_present,
 (settings_json::jsonb ? 'required_sources') AS required_sources_present
 FROM analytics_automation_policy WHERE id=1;
SELECT status,count(*) FROM analytics_automation_identities GROUP BY status;
SELECT count(*) AS stable_ids_with_multiple_verified_subjects FROM (
 SELECT client_id FROM analytics_automation_identities WHERE status='VERIFIED'
 GROUP BY client_id HAVING count(*)>1
) conflicts;
SELECT indexname,indexdef FROM pg_indexes
 WHERE schemaname='public' AND tablename IN ('analytics_automation_releases','analytics_automation_events');
ROLLBACK;
```

JSONB cast в проверках ключей нужен, потому что settings_json хранится как JSON. Полный settings/manifest/evidence не выводить: могут содержать sensitive идентификаторы. Количество VERIFIED не доказывает корректность review.

## 3. Calltrack MySQL/MariaDB — только DB CLI и READ ONLY

Выбрать **существующий** protected readonly client defaults-file и правильную БД приватно. Не вызывать `getPdo()`/bootstrap/ensure* и не создавать нового пользователя ради проверки. Не передавать пароль через `-p…`. Сначала `SELECT VERSION()`; для MariaDB установить session `max_statement_time=5`, для MySQL — `max_execution_time=5000`. Это session limits, не изменение данных. Если тип сервера неизвестен, не запускать полные COUNT.

Запуск в DB CLI с ON_ERROR_STOP-эквивалентом (не `--force`), затем:

```sql
SET SESSION innodb_lock_wait_timeout=1;
SET SESSION lock_wait_timeout=1;
START TRANSACTION READ ONLY;
SELECT table_name,engine,table_rows FROM information_schema.tables
 WHERE table_schema=DATABASE() AND table_name IN (
 'client_manager_bindings','analytics_client_bindings','manager_tasks','manager_task_events',
 'analytics_inbox','analytics_processing_events','analytics_sync_state','analytics_task_policy',
 'analytics_task_type_policy','analytics_task_budget','analytics_task_budget_events','analytics_automation_sync_state');
SELECT table_name,column_name,column_type,is_nullable
 FROM information_schema.columns WHERE table_schema=DATABASE()
 AND table_name IN ('client_manager_bindings','analytics_client_bindings','analytics_task_budget','analytics_automation_sync_state')
 ORDER BY table_name,ordinal_position;
SELECT id,publication_sequence,current_run_id,current_sequence,after_change FROM analytics_sync_state WHERE id=1;
SELECT id,approved,revision,min_confidence FROM analytics_task_policy WHERE id=1;
SELECT signal_type,enabled,priority,due_days FROM analytics_task_type_policy ORDER BY signal_type;
SELECT COUNT(*) AS mappings,
 COALESCE(SUM(u.id IS NULL OR u.role<>'manager' OR u.is_active<>1),0) AS ineligible_targets
 FROM client_manager_bindings b LEFT JOIN web_users u ON u.id=b.web_user_id;
SELECT status,COUNT(*) AS bindings FROM analytics_client_bindings GROUP BY status;
ROLLBACK;
```

Не исполнять SELECT из отсутствующих таблиц: после metadata расхождения — STOP. table_rows — оценка InnoDB, не контроль целостности. Сверить SHOW CREATE TABLE/SHOW INDEX нужных таблиц с миграциями (только DDL, без данных), обязательные UNIQUE task signal/slot и FK assignee. Не считать существование таблицы доказательством применения всей миграции.

Только после согласованной новой миграции:

```sql
START TRANSACTION READ ONLY;
SELECT id,approved,max_tasks,used_tasks,revision FROM analytics_task_budget WHERE id=1;
SELECT id,publication_sequence,current_run_id,current_sequence,after_change FROM analytics_automation_sync_state WHERE id=1;
ROLLBACK;
```

Ожидаемый initial budget NULL/0/0; approved cursor0 независим от shadow cursor. Не обнулять неожиданные значения: остановиться и исследовать.

## 4. Точный client_id и ответственный — приватный выбор одного клиента

Безопасно выбранный существующий client_id должен быть подтверждён **в каноническом ClientsVR**, а не получен из совпадения имени/телефона. Структура прямого ClientsVR DB/API lookup по ID в этом окружении не подтверждена: не угадывать endpoint/SQL. Владелец предоставляет metadata фактического источника и минимальный redacted результат точечного PK lookup или проверяет приватно через существующую карточку. Не загружать 322655 записей.

Дополнительно можно проверить только точечную запись **существующего** Calltrack SQLite cache. Не использовать `clientsSqliteFile()` для определения пути: helper может создать директорию. Владелец задаёт известный существующий путь и private ID в защищённом окружении; не вставляет ID/телефон в публичный вывод:

```python
# Выполнить локально на сервере только с уже известными private env значениями.
import os,json,sqlite3
from pathlib import Path
p=Path(os.environ['CLIENTS_SQLITE_PATH']).resolve()
selected=os.environ['CHURN_CHECK_CLIENT_ID']
if not p.is_file() or not selected: raise RuntimeError('Existing cache and selected stable ID required')
conn=sqlite3.connect(p.as_uri()+'?mode=ro',uri=True,timeout=1)
conn.execute('PRAGMA query_only=ON')
try:
    conn.execute('BEGIN')
    row=conn.execute('SELECT client_id,payload_json FROM clients WHERE client_id=?',(selected,)).fetchone()
    result={'cached_record_found':row is not None}
    if row:
        record=json.loads(row[1])
        fields=record.get('fields') or {}
        result.update(payload_id_matches_index=str(record.get('id',fields.get('id','')))==str(row[0]),
                      manager_field_present=any(isinstance(fields.get(k),str) and fields[k].strip() for k in ('manager','Менеджер')))
    print(result)
finally:
    conn.rollback();conn.close()
```

Это readonly query, не refresh/repair кэша. Если live SQLite/WAL не допускает безопасного чтения, не checkpoint/переключать journal mode: остановиться и согласовать существующий проверенный snapshot. Успех lookup не доказывает свежесть cache относительно ClientsVR. Сверить generation/source revision/время последней **успешной полной** синхронизации с независимым ClientsVR подтверждением; mtime/размер — только наблюдение.

Приватно проверить через точечные parameterized SELECT:

1. canonical ClientsVR ID существует, не archived, соответствует именно выбранному клиенту;
2. поля manager/Менеджер не конфликтуют по штатному pure `clientManagerField`, а designation_key имеет ровно одну explicit binding к активному Calltrack manager;
3. producer scope-binding указывает на тот же stable ID и expected user ID, consumer binding VERIFIED/nontechnical; revision/evidence актуальны;
4. проверена вся история stable ID, переименования и телефоны; несколько VERIFIED producer subject на ID означают блокировку, не автоматическое объединение;
5. одно имя/телефон у разных ID не является основанием объединять их. Без независимого подтверждения всей группы продаж оставить REVIEW/AMBIGUOUS;
6. ошибка/отсутствие таблиц/direct ID contract — NOT VERIFIED/BLOCKED, не PASS.

Использовать только bounded PK/index lookup и агрегированные булевы выводы. Точные значения и доказательства хранить в private evidence. Не запускать полный name/phone GROUP BY по sales и не переписывать историю.

## 5. Что эти проверки не разрешают

Ни metadata, ни COUNT не разрешают менять UNKNOWN. Не запускать production `release`, worker, sync dry_run или API admin dry-run под видом чтения: producer preview берёт блокировки, consumer dry_run пишет inbox/audit/cursor/cache. HTTP GET также может писать access audit. Такие диагностические действия требуют отдельного согласования и не входят в строгую DB READ-ONLY сверку.

Итог сверки — версии/схема/безопасные flags, наличие источников/противоречий и PRIVATE подтверждение конкретной identity. Полнота upstream требует отдельного manifest/source registry и утверждения владельца. До этого production остаётся SHADOW/automation_allowed=false.
