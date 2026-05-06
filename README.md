# Calltrack (Corporate Dialer)

Android-приложение для корпоративных устройств отдела продаж:
- выполняет звонки как dialer;
- отслеживает входящие/исходящие состояния звонков;
- собирает аналитику (тип, длительность, заметка);
- отправляет события в Google Sheets через webhook;
- при отсутствии сети откладывает отправку (Room-очередь).

## Технологии
- Kotlin, Android SDK
- MVVM + Repository
- Room (локальная очередь)
- Retrofit + OkHttp (webhook)
- DataStore (флаг onboarding)
- Foreground Service
- TelephonyCallback (Android 12+) + PhoneStateListener fallback
- Activity Result API

---

## 1. Как собрать проект
1. Откройте проект в **Android Studio Hedgehog+** (или новее).
2. Дождитесь Gradle Sync.
3. Проверьте `minSdk=26`, `targetSdk=35` в `app/build.gradle`.
4. Выполните сборку:
   - через меню: **Build → Make Project**;
   - или в терминале: `./gradlew :app:assembleDebug`.
5. Готовый APK: `app/build/outputs/apk/debug/app-debug.apk`.

---

## 2. Как установить APK
1. Скопируйте `app-debug.apk` на устройство.
2. На устройстве разрешите установку из неизвестных источников:
   - **Settings → Security / Apps → Install unknown apps**.
3. Установите APK через файловый менеджер.
4. После установки откройте приложение `Calltrack`.

---

## 3. Как настроить приложение
При первом запуске откроется onboarding:
1. Экран приветствия.
2. Выдача разрешений:
   - `READ_PHONE_STATE`
   - `READ_CALL_LOG`
   - `CALL_PHONE`
   - `RECORD_AUDIO`
   - `INTERNET`
3. Экран **Авторизация**: введите обязательное поле `ФИО` и нажмите `Ок`.
4. Завершение и вход в основной экран.

### Важно для корпоративных устройств
- Отключите энергосбережение для приложения:
  - **Settings → Battery → Unrestricted / Don't optimize**.
- Разрешите автозапуск приложения (если есть политика производителя).
- Разрешите уведомления (для Foreground Service).

---

## 4. Настройка Google Sheets интеграции

### Шаг 1. Создать Google Apps Script
1. Откройте Google Sheets.
2. **Extensions → Apps Script**.

### Шаг 2. Вставить webhook-код
```javascript
function doPost(e) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var data = JSON.parse(e.postData.contents);
  var comment = data.comment || data.note || "";
  var reminderText = data.reminder_text || data.reminderText || "";
  var callId = String(data.call_id || "");

  // Сопоставляем данные по названиям колонок, а не по фиксированному порядку.
  // Это защищает от "съезда" значений по колонкам.
  var header = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  var headerIndex = {};
  for (var h = 0; h < header.length; h++) {
    headerIndex[String(header[h]).trim()] = h;
  }

  var rowValues = new Array(header.length).fill("");
  function put(colName, value) {
    var idx = headerIndex[colName];
    if (idx === undefined) return;
    rowValues[idx] = value;
  }

  put("Дата", data.date || "");
  put("Время", data.time || "");
  put("Номер телефона", data.phone || "");
  put("Тип звонка", data.type || "");
  put("Длительность", data.duration || "");
  put("Менеджер", data.manager || "");
  put("Комментарий", comment);
  put("Тег", data.tag || "");
  put("Напоминание", data.reminder || "");
  put("Текст напоминания", reminderText);
  put("Клиент", data.client || "");
  put("ID", callId);

  // Ищем уже существующую строку по call_id в колонке L (12-я колонка).
  // Это позволяет обновлять ту же строку после заполнения "Результат звонка",
  // а не добавлять новую.
  var lastRow = sheet.getLastRow();
  var targetRow = 0;
  var idColumn = (headerIndex["ID"] || 11) + 1;
  if (callId && callId !== "" && lastRow > 1) {
    var ids = sheet.getRange(2, idColumn, lastRow - 1, 1).getValues();
    for (var i = 0; i < ids.length; i++) {
      if (String(ids[i][0]) === callId) {
        targetRow = i + 2;
        break;
      }
    }
  }

  Logger.log("callId=" + callId + ", targetRow=" + targetRow);

  if (targetRow > 0 && callId !== "") {
    sheet.getRange(targetRow, 1, 1, rowValues.length).setValues([rowValues]);
  } else {
    sheet.appendRow(rowValues);
  }

  return ContentService.createTextOutput("ok");
}
```

### Шаг 3. Опубликовать
1. **Deploy → New deployment**.
2. Тип: **Web App**.
3. Доступ: **Anyone**.
4. Скопируйте URL web app.

### Шаг 4. Вставить URL в приложение
В `app/build.gradle` замените:
```gradle
buildConfigField "String", "WEBHOOK_URL", '"https://script.google.com/macros/s/AKfycbzeZKY0kOvV2gFVfrxvIGlt6jRk2sKGr6IhleWILIb6UCvE9hLXBjjJmskaeK8pDF5U4w/exec"'
```
на ваш реальный URL.

### Шаг 5. Связать 2 таблицы-справочника клиентов (поиск Телефон → Клиент)
Приложение читает справочник клиентов напрямую из Google Sheets (CSV export) и ищет номер в колонке **Телефон**.
Если номер найден — берёт значение из колонки **Клиент** и отправляет его в основной CallTrack webhook.

1. Откройте таблицу-справочник:
   `https://docs.google.com/spreadsheets/d/1Wl4UXI_x0a7A0iPYuW_ZRlrf3xEdVKMnOALi9p6J_Mc/edit`
2. Убедитесь, что у вас есть **2 листа** (или больше), где есть колонки с точными заголовками:
   - `Телефон`
   - `Клиент`
3. Для каждого листа возьмите `gid`:
   - откройте нужный лист;
   - в URL будет параметр `gid=...` (например `gid=0`).
4. В `app/build.gradle` укажите ID таблицы и список gid через запятую:
```gradle
buildConfigField "String", "CLIENT_DIRECTORY_SPREADSHEET_ID", '"1Wl4UXI_x0a7A0iPYuW_ZRlrf3xEdVKMnOALi9p6J_Mc"'
buildConfigField "String", "CLIENT_DIRECTORY_SHEET_GIDS", '"0,123456789"'
```
5. Пересоберите приложение.

Важно:
- порядок gid задаёт приоритет поиска (сначала первый gid, потом второй);
- сравнение номеров делается после нормализации (только цифры, последние 10);
- если клиент не найден, в webhook уходит `"-"` в поле `client`.

---

## 5. Формат данных
POST JSON:
```json
{
  "call_id": "12345_1714300000000",
  "date": "20.04.26",
  "time": "14:35",
  "phone": "+79999999999",
  "type": "Исходящий",
  "duration": 120,
  "manager": "Иванов Иван",
  "note": "",
  "tag": "",
  "reminder": "",
  "reminder_text": "",
  "client": "ООО Ромашка"
}
```

Поля:
- `call_id` — уникальный идентификатор звонка в формате `<id>_<timestamp>`. Используется скриптом для обновления **той же строки** вместо добавления новой.
- `date` — дата звонка в формате `дд.мм.гг`.
- `time` — время звонка в формате `чч:мм`.
- `phone` — номер телефона.
- `type` — тип звонка (`Входящий`, `Исходящий`, `Пропущенный`, `Неотвеченный`).
- `duration` — длительность в секундах.
- `manager` — ФИО менеджера (сохраняется при первом запуске на экране «Авторизация»).
- `note` — комментарий.
- `tag` — тег.
- `reminder` — напоминание.
- `reminder_text` — текст напоминания.
- `client` — клиент из справочника.

---

## Структура проекта
- `ui/` — экраны (onboarding, dial pad, история)
- `service/CallTrackingService` — фоновое слежение
- `telephony/CallStateTracker` — callback/fallback API
- `data/local` — Room
- `data/remote` — Retrofit webhook
- `data/repository` — репозиторий + DataStore
- `utils/CallUtils` — helper-функции

---

## Примечания по продакшену
Для реального deployment рекомендуется добавить:
- `WorkManager` для периодического ретрая синка;
- шифрование локальной БД;
- MDM-политику для фиксирования default dialer;
- более точную классификацию звонков через CallLog-парсинг.
