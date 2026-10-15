# История клиента: реализация и проверка (15 октября 2026)

На момент первоначального отчёта изменения были оставлены незакоммиченными. Затем пользователь отдельно разрешил публикацию на GitHub: файлы этой доработки включены в отдельный коммит ветки `work`. Исходные Android/MAX-изменения исключены. Production не изменялся.

## Аудит двух проектов

Calltrack: PHP/PDO MySQL/MariaDB, интерфейс `analizmop/index.html` с vanilla JavaScript, `analizmop/api.js` с единым requestJson/CSRF, существующий Chart.js. Клиентская история строилась из загруженных `get_calls.php` звонков с комментариями, тегами и напоминаниями. Клиент определяется по локальному индексу Clients/1С (SQLite либо shards). Отсутствие готового справочника отличается от отсутствия клиента.

Web authentication: `web_users`, cookie session, `requireWebUser`, `webManagerScope`; manager ограничен `web-user-{id}`, admin/supervisor имеют общий предусмотренный доступ. Изменяющие web-запросы требуют X-CSRF-Token. Android использует Bearer tokens той же учётной записи.

Personal contacts: существующая таблица `personal_contacts(user_phone,contact_phone,personal_flag,updated_at)`, `personal_contact.php`, `get_personal_contacts.php`. Android `CallRepository.refreshPersonalContactsFromSql()` обновляет DAO и локальные контакты через `updatePersonalContactLocal`; новый механизм и изменения Kotlin не требуются. Найденный пробел: серверная выдача звонков/статистики не исключала звонки по таблице personal_contacts.

Sales Journal изучен до изменений через GitHub connector, затем клонирован для локальной проверки: HEAD `cd5589fdb63988512433074f5696a7b8118ca34b`. FastAPI/SQLAlchemy/PostgreSQL; Sale.id — стабильный ID продажи, `sale_date`, `document_number`, `client`, `phone`, `total_amount Numeric(18,2)`, SaleItem с quantity/ценами. Стабильного внешнего client_id в Sale нет. Подтверждены `routers/calltrack_integration.py`, `schemas/calltrack.py`, `services/calltrack_integration.py`, `repositories/sales.py`, `services/sale_details.py` и документация контракта. POST `/api/integrations/calltrack/client-sales` получает до 500 ссылок и включительный диапазон дат; один SQL без товаров, дедупликация Sale.id, телефон приоритетнее резервного имени. GET `/api/integrations/calltrack/sales/{sale_id}` возвращает карточку с товарами; оба защищены CALLTRACK_INTEGRATION_TOKEN. Изменений в Sales Journal не потребовалось.

## Изменённые файлы

- `analizmop/index.html`: полноэкранная разметка, вкладки, инструкция 1С, ссылка на сохранённое взаимодействие, интеграция существующей карточки продажи; точное форматирование денежных строк и расчёт стоимости товарной позиции через BigInt.
- `api/config.php`: единый фильтр рабочих звонков в buildFilters (применяется get_calls и dashboard).
- `api/personal_contact.php`: web-действие по авторизованному call_id, прежняя Android/admin совместимость; транзакционное согласование старых форматов одного номера без удаления строк.
- `api/sales_journal.php`: исключение личных звонков при формировании списка клиентов коммуникаций.
- `api/android_client_timeline.php`: та же фильтрация рабочих звонков.
- `tests/web_auth_access_test.php`: статическая проверка обновлена для разрешённой web-ветки при сохранённой Android-ветке; реальная совместимость проверяется HTTP-тестом.
- `tests/client_sales_timeline_test.php`: проверяется вызов точного расчёта стоимости позиции вместо обязательного старого float-выражения; точность отдельно проверяется Node-тестом.

## Созданные файлы

- `analizmop/client-workspace.css`, `analizmop/client-workspace.js`.
- `api/client_history_context.php`, `api/client_purchases.php`, `api/purchase_analytics.php`, `api/personal_contacts_filter.php`.
- `tests/purchase_analytics_test.php`, `tests/purchase_money_test.js`.
- `tests/client_workspace_http_test.py`, `tests/client_workspace_browser_test.py`.
- `tests/fixtures/client-workspace/seed.php`, `tests/fixtures/client-workspace/sales-router.php` (только тесты, требуют CALLTRACK_TEST_FIXTURES=1).
- Этот отчёт `docs/client-workspace.md`.

## Исходные чужие изменения

До работы были: modified `app/src/main/AndroidManifest.xml`; untracked `app/src/main/java/com/example/calltrack/accessibility/MaxAccessibilityDiagnosticService.kt` и `app/src/main/res/xml/max_accessibility_service_config.xml`. Все сохранены без изменений, SHA-256 сверены. `CallRepository.kt` исходно не был dirty и также не изменялся. Исходные status/diff/checksums сохранены вне репозитория в `/workspace/task-audit/`.

## Поведение UI и personal contacts

Карточка остаётся modal: практически весь viewport, 12px внешнего отступа (4px на узком экране), ограничение через dvh, собственный scroll; страница позади заблокирована. Информация и записи на «Общее» сохранены; записи расположены адаптивной сеткой. Первоначально открывается «Общее». Вкладки имеют tab/tabpanel, клавиатурное переключение; загруженное дерево и раскрытые узлы сохраняются при переходе между вкладками.

Один keyboard listener и один observer обслуживают стек клиентской карточки, продажи и инструкции 1С. Esc закрывает только верхнее окно. Нижнее окно inert, Tab удерживается в активном окне, фокус возвращается к открывшему элементу. Существующие кнопки закрытия и клик по фону сохранены. Смена клиента/закрытие инвалидирует старые async-ответы; повторное открытие не накапливает обработчики.

Красный блок зависит от backend `found_in_1c`, полученного из готового справочника по номеру сохранённого звонка (для email — по адресу). Ошибка справочника показывается как ошибка, а не «клиент не найден». Для взаимодействий без телефона действие не предлагается. Инструкция 1С содержит заданный текст, выделенный последний абзац и «Понятно»; обращений записи в 1С нет.

Пометка отправляет только call_id в существующий `personal_contact.php`. Manager не может выбрать чужой звонок; владелец и manager взяты из разрешённой записи, переданные user_phone/manager/personal_flag в этом режиме игнорируются. Admin/supervisor могут действовать по доступному им звонку. Сохраняется personal_flag=1 в существующей таблице. Обновляются также старые эквивалентные варианты телефона, чтобы Android не получил противоречивые flags после нормализации. Операция транзакционная, звонки не удаляются. UI сразу убирает соответствующие звонки владельца, пересчитывает списки/статистику и затем перечитывает backend.

Фильтр `NOT EXISTS` сопоставляет owner и нормализованный телефон; применяется до LIMIT/COUNT/агрегаций. Исключаются исторические и будущие звонки, включая рабочую Android timeline и коммуникации для продаж. Существующая метка «Личный звонок» также исключается. Нормализация повторяет last-ten-digits механизм Clients. Сравнение работает и при разных collation calls/personal_contacts. Android получает данные прежним GET и меняет локальный статус следующим существующим циклом синхронизации. Локальная Android БД/устройство здесь не запускались.

## История покупок, API и безопасность

Browser → authenticated Calltrack backend → существующие Sales Journal transport/cache → Browser. Новый `client_purchases.php` принимает call_id либо email_id, затем разрешает сохранённое взаимодействие в серверной RBAC. Произвольные client_id/phone из браузера не используются. Email проверяется по каноническому имени менеджера в соответствии с существующей моделью. POST требует CSRF, даже для чтения.

Для найденного клиента используются его телефоны из записи 1С, объединённые через стабильный ID в локальном SQLite (либо телефоны той же записи shard). Sales Journal не хранит ID клиента, поэтому фактическое сопоставление продаж выполняется по этим нормализованным телефонам. Резервное сопоставление только по имени для новой карточки отключено. Общие/неоднозначные телефоны дают понятную ошибку; никакого предположительного объединения одноимённых клиентов нет.

Один существующий batch покрывает всю доступную историю `0001-01-01..9999-12-31`; до 500 телефонов одного клиента, при превышении — явная ошибка. Повторно используется штатный кэш: по умолчанию fresh 600 секунд, stale до 86400 секунд при отказе источника, stale явно отмечен. Это кэш кратких продаж конкретного клиента, а не копия Sales Journal DB. Токен остаётся только в backend конфигурации и Authorization server-to-server.

Дерево строится из тех же дедуплицированных продаж, что и аналитика: year → month → date → sale. Итоги каждого уровня вычисляются точно. Годы/месяцы/даты по убыванию, продажи одного дня по sale_id. Дерево исходно свёрнуто, DOM дочерних узлов создаётся при раскрытии. Фильтры диаграммы никогда не фильтруют дерево. По клику переиспользуется существующая карточка продажи с товарами через `sale_detail.php`; её session allow-list заполняется только после авторизованной загрузки. Проверка allow-list выполняется до общего кэша, подмена чужого sale_id возвращает 403.

## Аналитика и деньги

- Используется уже подключённый Chart.js, без нового framework/CDN. Текущая серия сплошная зелёная, прошлая серая пунктирная; легенда, общий tooltip, counts и доступная таблица значений.
- Агрегация и дерево выполняются PHP на backend. Денежные строки Numeric(18,2) переводятся в int64 копейки; сложение проверяет overflow, неверные суммы отклоняются. В ответе — decimal strings. JS форматирует строки без Number; Number используется только для геометрии Chart.js. Процент — округлённый отображаемый ratio, не источник денежных сумм. Стоимость позиции в существующей карточке продажи рассчитывается BigInt с округлением до копеек.
- По умолчанию первый день текущего месяца минус 11 месяцев → последний день текущего месяца, Europe/Moscow. Для октября 2026 это ноябрь 2025 — октябрь 2026, ровно 12 monthly buckets.
- Детализации day/month/year. Нулевые точки создаются до распределения продаж в обеих сериях. Дневная детализация ограничена 24 месяцами; месячная/годовая — 100 годами. Ошибки понятные, обрезки нет.
- Comparison автоматически сдвигает календарные месяцы на год: ноябрь 2024 — октябрь 2025 для стандартного примера. Границы включительные, дни календарные, не rolling 365 days.
- Сопоставление по календарному году/месяцу/дню. Для дневного февраля берётся объединение календарных дней двух лет: отдельный bucket 29 февраля с нулём и null date на отсутствующей стороне. Продажа 29 февраля не теряется, 1 марта не сдвигается. Обе серии имеют одинаковую длину.
- Absolute change = current − previous. Percentage = (current − previous) / previous × 100, отображение до 1 знака. При previous=0 процент null, UI «нет базы для сравнения», абсолютная разница остаётся. Отрицательная динамика поддерживается.
- Открытие «Общее»: один лёгкий context request без Sales Journal. Первая вкладка покупок: один HTTP Browser→Calltrack и один batch Calltrack→Sales Journal (либо 0 upstream при cache hit) для обеих серий и всего дерева. Смена фильтров: один Browser→Calltrack, обычно 0 upstream благодаря тому же кэшу; после истечения кэша один batch. Запросов по числу buckets нет. Карточка продажи делает отдельный запрос только по клику.
- Loading/error/retry независимы от «Общее»; аналитическая ошибка не скрывает дерево. Предыдущая диаграмма очищается при смене параметров, чтобы старый период не выглядел новым. Пустая история и отсутствие покупок в периоде различаются.

## Проверки

- PHP syntax: все изменённые/новые backend и fixture PHP-файлы корректны.
- `purchase_analytics_test.php`: 52 проверки, включая default 12 months, нули, копейки, суммы day/month/year/tree, multi-year boundary, положительную/отрицательную YoY, previous=0, оба направления високосного сравнения и сумму comparative period.
- `purchase_money_test.js`: точные большие денежные строки, умножение цены на дробное количество, округление и отрицательные значения.
- `client_workspace_http_test.py`: 51 HTTP-проверка на отдельной MariaDB/PHP: anonymous, CSRF, manager IDOR звонков/email/продаж, подмена owner, admin/supervisor, 1С flags, all-phone sums, сохранение полного дерева, cache/no N+1, исторические и будущие звонки, статистика, Android timeline/GET/POST, нормализация дубликатов и обратимость без удаления звонков.
- `client_workspace_browser_test.py`: Chromium, реальный клик в «Истории клиентов», desktop 1366×768 / mobile 390×844; вкладки, fullscreen, Chart.js, ленивое дерево, товарная карточка, вложенный Esc (продажа и инструкция), повторное открытие, async races, сохранение дерева при смене периода, немедленная пометка личного контакта.
- Существующие Node-тесты `client_sales_grouping_test.js`, `client_history_summary_test.js` проходят; синтаксис внешнего и inline JavaScript корректен.
- Полный PHP regression-набор: исходный HEAD — 34/43 успешно, текущая версия с новым тестом — 35/44. Те же 9 прежних ошибок: admin_updates, android_dial_layout, android_kotlin_encoding, client_calls_names, client_directory, help_import_invoice, install_update_for_all, pwa_barcode_scanner, web_pwa_removal. Сравнение выполнено с отдельным `git archive HEAD`, без изменения рабочего дерева. Тестовые assertions для нового auth branching и точного расчёта обновлены, поведения покрыты отдельными HTTP/Node-проверками.
- Sales Journal `pytest -q tests/test_calltrack_integration.py`: 14 passed. Production Sales Journal/Clients не вызывались; end-to-end Calltrack тесты использовали локальный fixture upstream с проверенным реальным контрактом.
- Android пользовательские файлы и CallRepository.kt: SHA-256 совпадают с исходными.
- `git diff --check`: без ошибок. Точные итоговые `git status --short` / `git diff --stat` приведены ниже после завершения проверки; untracked-файлы в обычный diff --stat не входят.

## Воспроизведение новых тестов

Чистые проверки: `php tests/purchase_analytics_test.php`, `node tests/purchase_money_test.js`.

HTTP/browser тесты запускаются только в отдельной тестовой БД. Создать штатную схему `database/create_calls_table.sql`, настроить отдельный PHP server и storage, не используя production config. В web-container задать `CALLTRACK_TEST_FIXTURES=1`, `CALLTRACK_STORAGE_DIR` в тестовый каталог, DB_HOST/DB_NAME тестовой БД. Через отдельный `/etc/calltrack/config.local.php` задать `SALES_JOURNAL_BASE_URL=http://127.0.0.1:8091` и `CALLTRACK_INTEGRATION_TOKEN=test-only-token`. Seed: `php tests/fixtures/client-workspace/seed.php`. Upstream: `php -S 127.0.0.1:8091 tests/fixtures/client-workspace/sales-router.php`. Опубликовать проект под `/vr/calltrack/` на localhost:8090. Seed только для отдельной пустой БД, фиксированные тестовые учётные записи/токен нельзя использовать в production.

`python3 tests/client_workspace_http_test.py` (stdlib, CALLTRACK_TEST_URL опционально). `python3 tests/client_workspace_browser_test.py` (Playwright, `/usr/bin/chromium`, CALLTRACK_TEST_PAGE и CALLTRACK_TEST_CHART — локальная копия Chart.js для перехвата существующего CDN). Тест фиксирует браузерную дату 15.10.2026. Сначала HTTP, затем browser; тесты восстанавливают пометку личного тестового контакта.

## Ограничения и ручная проверка перед production

1. В Sales Journal нет стабильного ID клиента: продажи без совпадающего телефона нельзя безопасно приписать только по имени. Они не появляются в новой карточке. В UI показана вся доступная история по подтверждённым телефонам, а не предположительные совпадения имён. Смена владельца номера в прошлом тоже не моделируется текущим контрактом.
2. Однозначность и актуальность зависят от штатного кэша Clients/1С; ошибка/неоднозначность не маскируется пустой историей. Для email без однозначного телефона покупки недоступны с объяснением.
3. История отдельного клиента загружается целиком: проверить время/память/cache size на клиенте с максимальным числом продаж. Товары загружаются только по клику. Необходимость серверной пагинации нельзя оценить без реальных объёмов.
4. SQL использует REGEXP_REPLACE и 64-bit PHP; тестировалось на MariaDB 11.4/PHP 8.3. Проверить версию production MySQL/MariaDB, индексы owner и EXPLAIN рабочего фильтра на реальном количестве звонков.
5. Сохраняется прежнее CDN-подключение Chart.js; при его недоступности есть таблица значений. Проверить текущие CSP и доступность CDN на production. Новый внешний ресурс не добавлялся.
6. Проверить действующие URL/token Sales Journal, сумму и список документов выбранного реального клиента за весь период и YoY, включая несколько телефонов/29 февраля. Проверить количество продаж без телефона до принятия ограничения из пункта 1.
7. На реальном установленном Android: web-пометка → штатная синхронизация → «Личный» в контактах → отсутствие звонков в рабочих списках/статистике; проверить и снятие пометки. В этой задаче проверен HTTP-контракт и существующий Kotlin-код, не устройство.
8. Проверить настоящий справочник email/канонические имена менеджеров и права admin/manager/supervisor. Session allow-list карточки продажи сохраняет существующее время действия 30 минут; после истечения понадобится повторная загрузка покупок/открытие карточки клиента.
9. Проверить сохранение комментариев/напоминаний в «Общее», фокус и scroll в используемых браузерах ноутбуков. Не загружать test fixtures/тестовые конфигурации в production.

## Git status и diff stat до публикации

`git status --short`:

```text
 M analizmop/index.html
 M api/android_client_timeline.php
 M api/config.php
 M api/personal_contact.php
 M api/sales_journal.php
 M app/src/main/AndroidManifest.xml
 M tests/client_sales_timeline_test.php
 M tests/web_auth_access_test.php
?? analizmop/client-workspace.css
?? analizmop/client-workspace.js
?? api/client_history_context.php
?? api/client_purchases.php
?? api/personal_contacts_filter.php
?? api/purchase_analytics.php
?? app/src/main/java/com/example/calltrack/accessibility/
?? app/src/main/res/xml/max_accessibility_service_config.xml
?? docs/client-workspace.md
?? tests/client_workspace_browser_test.py
?? tests/client_workspace_http_test.py
?? tests/fixtures/
?? tests/purchase_analytics_test.php
?? tests/purchase_money_test.js
```

`git diff --stat` (только tracked; AndroidManifest — исходное чужое изменение):

```text
 analizmop/index.html                 | 59 ++++++++++++++++++++++++++++--------
 api/android_client_timeline.php      |  4 ++-
 api/config.php                       |  3 +-
 api/personal_contact.php             | 46 ++++++++++++++++++++++++----
 api/sales_journal.php                |  3 +-
 app/src/main/AndroidManifest.xml     | 12 ++++++++
 tests/client_sales_timeline_test.php |  2 +-
 tests/web_auth_access_test.php       |  5 ++-
 8 files changed, 111 insertions(+), 23 deletions(-)
```

Sales Journal: `git status --short` и `git diff --stat` пусты. Новые untracked-файлы перечислены выше и оставлены для ручного просмотра без staging.
