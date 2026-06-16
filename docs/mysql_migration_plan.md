# Миграция CallTrack с Google Таблицы на MySQL/MariaDB Timeweb

## 1. Структура базы

SQL-скрипт находится в `database/create_calls_table.sql`. Его нужно выполнить в базе MySQL/MariaDB, созданной в панели Timeweb.

Соответствие колонок:

| Google Sheets | MySQL |
|---|---|
| Дата | `call_date` |
| Время | `call_time` |
| Номер телефона | `phone` |
| Тип звонка | `call_type` |
| Длительность | `duration` |
| Менеджер | `manager` |
| Комментарий | `comment` |
| Тег | `tag` |
| Напоминание | `reminder` |
| Текст напоминания | `reminder_text` |
| Клиент | `client` |
| ID | `call_id` |
| Номер телефона пользователя | `user_phone` |

## 2. Пример конфигурации Timeweb

В `api/config.php` оставлены безопасные placeholders. На Timeweb можно задать реальные значения прямо в файле или через переменные окружения:

```php
const DB_HOST = 'localhost';
const DB_NAME = 'calltrack_db';
const DB_USER = 'calltrack_user';
const DB_PASS = 'strong_password';
```

Если Timeweb выдаёт отдельный hostname MySQL, используйте его вместо `localhost`, например `mysqlXX.timeweb.ru`.

## 3. PHP API

Загрузите папку `api/` на сайт Timeweb, например в `/public_html/calltrack/api/`.

### `POST /api/add_call.php`

Создаёт звонок или обновляет существующий по `call_id`.

```json
{
  "date": "2026-06-15",
  "time": "14:30:00",
  "phone": "+79001234567",
  "type": "Исходящий",
  "duration": 120,
  "manager": "Иван Иванов",
  "comment": "Комментарий",
  "tag": "Продажа",
  "reminder": "2026-06-16 10:00:00",
  "reminder_text": "Перезвонить",
  "client": "ООО Клиент",
  "call_id": "123_1780000000000",
  "user_phone": "+79998887766"
}
```

Ответ:

```json
{"status":"success"}
```

### `POST /api/update_call.php`

Частично обновляет запись по `call_id`. Можно отправлять только изменившиеся поля, например комментарий:

```json
{
  "call_id": "123_1780000000000",
  "comment": "Новый комментарий"
}
```

### `GET /api/get_history.php?phone=...&user_phone=...`

Возвращает историю звонков по номеру телефона, новые сверху. `user_phone` можно передавать для ограничения истории конкретным пользователем приложения.

### `GET /api/get_calls.php`

Фильтры:

- `manager`
- `phone`
- `user_phone`
- `date_from`
- `date_to`
- `limit`
- `offset`

Пример:

```text
/api/get_calls.php?manager=Иван%20Иванов&date_from=2026-06-01&date_to=2026-06-15
```

### `GET /api/dashboard.php`

Фильтры: `manager`, `phone`, `user_phone`, `date_from`, `date_to`.

Возвращает:

- `total_calls`
- `incoming_calls`
- `outgoing_calls`
- `missed_calls`
- `average_duration`
- `comments_count`
- `reminders_count`

## 4. Что заменить в Android-приложении

Android-код в этом PR не менялся автоматически. Для перехода на SQL нужно заменить текущий Google Apps Script URL:

- сейчас используется `BuildConfig.WEBHOOK_URL` из `app/build.gradle`;
- новый базовый URL должен указывать на Timeweb API, например:

```kotlin
buildConfigField "String", "SQL_API_BASE_URL", '"https://example.ru/calltrack/api/"'
```

Рекомендуемые соответствия методов приложения:

| Сейчас | Новый API |
|---|---|
| `CallRepository.sendCallToWebhook(...)` | `POST /api/add_call.php` |
| `CallRepository.syncCallById(...)` для комментариев/тегов/напоминаний | `POST /api/update_call.php` или `POST /api/add_call.php` |
| `CallRepository.loadHistoryFromRemote(phone)` | `GET /api/get_history.php?phone=...&user_phone=...` |
| Дашборд вместо чтения Google Sheets | `GET /api/dashboard.php` или `GET /api/get_calls.php` |

JSON для отправки звонка должен совпадать с `add_call.php`:

```json
{
  "date": "yyyy-MM-dd или dd.MM.yy",
  "time": "HH:mm:ss или HH:mm",
  "phone": "номер клиента",
  "type": "тип звонка",
  "duration": 0,
  "manager": "имя менеджера",
  "comment": "комментарий",
  "tag": "тег",
  "reminder": "yyyy-MM-dd HH:mm:ss",
  "reminder_text": "текст напоминания",
  "client": "клиент",
  "call_id": "стабильный ID звонка",
  "user_phone": "номер пользователя приложения"
}
```

## 5. Порядок миграции

1. Создать базу MySQL/MariaDB в панели Timeweb.
2. Выполнить `database/create_calls_table.sql`.
3. Загрузить папку `api/` на Timeweb.
4. Заполнить `api/config.php` реальными доступами или переменными окружения.
5. Проверить `POST /api/add_call.php` через curl/Postman.
6. Проверить `GET /api/get_history.php` и `GET /api/dashboard.php`.
7. В Android добавить новый Retrofit/OkHttp endpoint для SQL API.
8. Переключить запись звонков с Apps Script на `/api/add_call.php`.
9. Переключить обновление комментариев/тегов/напоминаний на `/api/update_call.php`.
10. Переключить историю и будущий дашборд на `/api/get_history.php`, `/api/get_calls.php`, `/api/dashboard.php`.
11. После проверки отключить запись в Google Sheets или оставить временное двойное логирование на период миграции.
