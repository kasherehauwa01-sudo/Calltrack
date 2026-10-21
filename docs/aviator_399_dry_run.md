# Проверка399 INACTIVITY кандидатов без задач

Дата: 21.10.2026, Europe/Moscow. Выполнять только на отдельной локальной/staging-машине, **не на production**. Входные CSV/ZIP/JSON содержат персональные данные: хранить вне Git и web root. Никакие данные не входят в commit/PR.

## Что именно проверяем

399 =178 PURCHASE_OVERDUE +221 CHURN_RISK — непубликуемые диагностические результаты на19.10.2026 из реального price-type export Авиаторов. Это не399 опубликованных AnalyticsSignal episodes. Штатный producer ранее вернул BLOCKED/UNKNOWN: import_batches не содержат declared completeness. Поэтому обычный `analytics_sync.php` не обязан увидеть399 и не должен обходить freshness ради этого числа.

Предлагаемая проверка — **offline replay уже вычисленных результатов**, не новый аналитический расчёт. Она проверяет корректность входного снимка, структуру сигналов, safety gates и идемпотентность399 receipts. Метрики/стадии копируются из исходного CSV, формулы не меняются и не вычисляются заново. Для пересчёта на другую дату/выгрузку нужен отдельный isolated engine run, а не замена as_of в этих строках.

Адаптер создаёт явные `diag-*` идентификаторы, не выдавая их за producer UUID; run.status=DIAGNOSTIC, mode=SHADOW, accepted_data_through=null, coverage/freshness=UNKNOWN, automation_allowed=false. Timestamp упаковки replay отдельно маркируется как НЕ время исходного расчёта. Настоящие ClientsVR IDs и user bindings не выдумываются. Нет записей в Sales Journal и публикационного pointer/feed.

## Проверенный вход

ZIP SHA256:

```text
ebf2970db8f2d8524960c0f544ac2bccb1ae9dafc017473722c9d36694acc0de
```

CSV SHA256 (исходные bytes, UTF-8 BOM/semicolon):

```text
daab44419876315c28844ed10e74ea7d0eec7f6c470c3ba48521ba517ce5bc30
```

Если checksum отличается — остановиться, не менять ожидаемый hash, пока причина не установлена. Не сохранять файл заново через Excel перед проверкой.

## Воспроизводимые команды

В отдельном checkout Calltrack ветки feat/analytics-manager-bindings (не server web root):

```bash
CSV=/private/analytics-review/inactivity-aviator-active.csv
sha256sum "$CSV"
OUTDIR=$(mktemp -d)
chmod 700 "$OUTDIR"
python3 scripts/diagnostic_inactivity_csv.py \
  --csv "$CSV" \
  --sha256 daab44419876315c28844ed10e74ea7d0eec7f6c470c3ba48521ba517ce5bc30 \
  --as-of 2026-10-19 \
  --out "$OUTDIR/replay.json"
```

Скрипт не открывает БД/сеть/конфиги, отказывается перезаписывать output, создаёт JSON с mode0600 и не допускает output внутри своего Git repo. До продолжения проверить print summary:399/178/221.

Запустить отдельный PHP container с выключенной сетью, source/input mounts read-only, без production volumes/env/credentials:

```bash
docker run --rm --network none \
  -v "$PWD:/app:ro" \
  -v "$OUTDIR/replay.json:/private/replay.json:ro" \
  -w /app \
  -e CALLTRACK_TEST_FIXTURES=1 \
  -e ANALYTICS_TASK_TEST_DSN=sqlite::memory: \
  php:8.3-cli-bookworm \
  php tests/fixtures/analytics/replay_diagnostic.php /private/replay.json \
  > "$OUTDIR/result.json"
cat "$OUTDIR/result.json"
```

Image должен иметь PHP8.1+, mbstring/PDO SQLite (проверенный8.3 image имеет). В managed cloud сохранять доверенные proxy/CA для первоначального pull; сам replay выполняется с `--network none`. Не передавать env-file, production credentials, volume с рабочим storage или MariaDB/PostgreSQL адрес. Хранилище теста — только SQLite `:memory:`. Проверка отказывается от другого DSN и не вызывает getPdo()/transport/catalog lookup. Тестовая схема создаётся в памяти, а не в production.

## Критерии успеха и измеренный результат

Проверено в этой среде21.10.2026:

```text
events=399
PURCHASE_OVERDUE=178
CHURN_RISK=221
BLOCKED=399
replay_deduplicated=true
tasks_created=0
task_events=0
bindings_created=0
automation_allowed=false
```

Код прогоняет все события **дважды**, затем assert399 inbox rows, ноль manager_tasks, task history/open slots/client bindings/manager bindings и unapproved policy. Исчезновение SQLite при завершении container исключает сохранение тестовых данных в рабочем Calltrack.

Все399 BLOCKED ожидаемы: SHADOW, неизвестная полнота Sales/ClientsVR, неподтверждённый scope, отсутствие импортированных проверенных ID/manager bindings и неутверждённая политика. Это **не измерение доли unmapped клиентов в production**: здесь evidence/bindings намеренно не загружались. Нельзя объявлять эти399 готовыми задачами или вручную подставлять client_id, manager, CONFIRMED, SUCCEEDED, approval/confidence/priorities ради прохождения gate.

Цена продажи Оптовые/Корпоративные остаётся отдельной от ClientsVR buyer_type Оптовик/Корпоративный. Replay сохраняет price-scope и не подтверждает buyer-type membership.

## Следующий разрешаемый отдельно тест

После серверной сверки и предоставления verified directory/bindings можно на изолированной **копии** БД проверить дополнительное покрытие identity/manager. Сохранять mode=dry_run, unapproved policy, отдельные credentials/storage и нулевые assertions задач/истории/slots. Нельзя запускать этот режим на production: обычный consumer dry_run пишет inbox/checkpoints и не является read-only операцией.

Полноценный live dry_run/staging требует настоящих опубликованных завершённых runs и согласованной популяции; текущий непубликуемый399 snapshot в эту роль не повышается. Включение enabled, утверждение0.65/NORMAL3/HIGH1 и production-операции по этому документу не разрешены.
